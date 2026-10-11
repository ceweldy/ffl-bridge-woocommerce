#!/usr/bin/env python3
"""Credential-free shadow runner. Publication is deliberately not implemented."""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import zipfile

PROFILE = json.loads(Path(__file__).with_name('profile.json').read_text())
SLUG = 'ffl-bridge-for-woocommerce'
GIT_BIN = '/usr/bin/git'  # Read-only system binary; never resolve source-controlled PATH entries.


def command(args, cwd, capture=False, timeout=None):
    env = None
    if args[0] == 'git':
        args = [GIT_BIN, '--no-replace-objects', *args[1:]]
        env = {key: value for key, value in os.environ.items() if not key.startswith('GIT_')}
        env.update(GIT_CONFIG_NOSYSTEM='1', GIT_CONFIG_GLOBAL=os.devnull)
    result = subprocess.run(args, cwd=cwd, env=env, text=True, check=True,
                            stdout=subprocess.PIPE if capture else None, timeout=timeout)
    return result.stdout.strip() if capture else ''



def regular_bytes(path, expected_size=None):
    # Reject parent links and special files before opening; NONBLOCK also prevents
    # a replaced FIFO from hanging between lstat and open. Host isolation is separate.
    for parent in path.parents:
        if parent.is_symlink():
            raise ValueError(f'linked parent directory is forbidden: {path.name}')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode):
            raise ValueError(f'only regular non-symlink files are accepted: {path.name}')
        if expected_size is not None and info.st_size != expected_size:
            raise ValueError(f'tracked file size changed: {path.name}')
        data = stream.read(info.st_size + 1)
        if len(data) != info.st_size:
            raise ValueError(f'file changed while reading: {path.name}')
        return data, info.st_mode


def source_manifest(args):
    if not re.fullmatch(r'[0-9a-f]{40}', args.sha):
        raise ValueError('SOURCE_SHA must be a full lowercase 40-character commit SHA')
    # Capture original commit object IDs and modes BEFORE any source command runs.
    # The same process-held manifest is used after the stage; never rebuild it then.
    tree = command(['git', 'ls-tree', '-r', '-l', '--full-tree', '-z', args.sha], args.source, True)
    manifest = {}
    for entry in tree.split('\0'):
        if not entry:
            continue
        metadata, name = entry.split('\t', 1)
        mode, kind, oid, size = metadata.split()
        if kind != 'blob' or mode not in ('100644', '100755'):
            raise ValueError(f'unsupported tracked file type: {name}')
        manifest[name] = (mode, oid, int(size))
    return manifest


def verify_source(args, manifest):
    index = {}
    for entry in command(['git', 'ls-files', '--stage', '-z'], args.source, True).split('\0'):
        if not entry:
            continue
        metadata, name = entry.split('\t', 1)
        mode, oid, stage = metadata.split()
        if stage != '0':
            raise ValueError('unmerged source index is forbidden')
        index[name] = (mode, oid)
    expected_index = {name: (mode, oid) for name, (mode, oid, _) in manifest.items()}
    if index != expected_index:
        raise ValueError('tracked index paths/blobs/modes differ from original source commit')
    for name, (mode, oid, size) in manifest.items():
        data, actual_mode = regular_bytes(args.source / name, size)
        normalized_mode = '100755' if actual_mode & 0o111 else '100644'
        blob = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
        if blob != oid or normalized_mode != mode:
            raise ValueError(f'tracked bytes or executable mode changed: {name}')


def write_receipt(path, text):
    if path.parent.is_symlink() or not path.parent.is_dir():
        raise ValueError('receipt directory must be a regular directory')
    # Atomic replacement never follows a target symlink or opens a target FIFO.
    fd, temporary = tempfile.mkstemp(prefix='.receipt-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            stream.write(text)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def context(args, manifest):
    if not re.fullmatch(r'[0-9a-f]{40}', args.sha):
        raise ValueError('SOURCE_SHA must be a full lowercase 40-character commit SHA')
    if command(['git', 'rev-parse', 'HEAD'], args.source, True) != args.sha:
        raise ValueError('checkout HEAD does not equal SOURCE_SHA')
    # Git status/index flags are not integrity evidence. Check actual bytes/modes.
    verify_source(args, manifest)
    pr = args.event == 'pull_request' and re.fullmatch(r'refs/pull/[1-9][0-9]*/merge', args.ref)
    tag = args.event == 'push' and re.fullmatch(r'refs/tags/v[0-9A-Za-z._-]+', args.ref)
    if args.workflow == 'ci':
        if not (pr or (args.event == 'push' and args.ref == 'refs/heads/main')):
            raise ValueError('CI accepts main pushes or pull-request merge refs')
    elif args.workflow == 'release':
        if not tag:
            raise ValueError('Release accepts only v* tag pushes')
    elif not PROFILE['wporg'] or not (pr or tag):
        raise ValueError('WordPress.org accepts public-repo pull requests or v* tag pushes')
    event_ref = args.ref.replace('refs/heads/', 'refs/remotes/origin/') if args.ref.startswith('refs/heads/') else args.ref
    if command(['git', 'rev-parse', event_ref + '^{commit}'], args.source, True) != args.sha:
        raise ValueError('event ref target does not equal SOURCE_SHA')
    if tag:
        header = re.search(r'^ \* Version:\s*(\S+)', (args.source / 'ffl-bridge.php').read_text(), re.M)
        if not header or args.ref.removeprefix('refs/tags/v') != header[1]:
            raise ValueError('release tag must match plugin version')
    os.environ['RELEASE_TAG'] = args.ref.removeprefix('refs/tags/') if tag else ''


def quality(args, receipt):
    actual = command(['php', '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'], args.source, True)
    if actual != args.php:
        raise ValueError(f'PHP lane requires {args.php}, found {actual}')
    composer = command(['composer', '--version'], args.source, True)
    if not composer.startswith('Composer version 2.'):
        raise ValueError('Composer v2 is required')
    receipt['runtime'] = {'php': actual, 'composer': composer,
                          'node': command(['node', '--version'], args.source, True)}
    for cmd in (['composer', 'validate', '--strict'],
                ['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist'],
                ['composer', 'audit', '--locked', '--no-interaction'], ['composer', 'check']):
        command(cmd, args.source)
    for path in sorted((args.source / 'assets/js').rglob('*.js')):
        command(['node', '--check', str(path)], args.source)
    if args.workflow == 'ci':
        command(['bash', '-n', 'bin/build-release.sh'], args.source)


def verify_assets(source, workflow):
    header = re.search(r'^ \* Version:\s*(\S+)', (source / 'ffl-bridge.php').read_text(), re.M)[1]
    names = [f'{SLUG}-{header}.zip']
    if workflow == 'wporg':
        names = [f'{SLUG}-{header}-wporg.zip']
    elif workflow == 'release' and PROFILE['wporg']:
        names.append(f'{SLUG}-{header}-wporg.zip')
    expected = {n for name in names for n in (name, name + '.sha256')}
    actual = {p.name for p in (source / 'dist').glob('*') if p.suffix in ('.zip', '.sha256')}
    if actual != expected:
        raise ValueError(f'wrong artifact set: expected {sorted(expected)}, found {sorted(actual)}')
    forbidden = r'(^|/)(\.distignore|\.git|\.github|\.wordpress-org|ci|Jenkinsfile|bin|tests|vendor|composer\.(json|lock)|php(cs|unit)\.xml\.dist|CHANGELOG\.md|CONTRIBUTING\.md|README\.md|SECURITY\.md)(/|$)'
    if PROFILE['wporg']:
        forbidden = forbidden.replace('|ci|', '|docs|ci|')
    manifest = []
    for name in sorted(expected):
        p = source / 'dist' / name
        data, _ = regular_bytes(p)
        digest = hashlib.sha256(data).hexdigest()
        manifest.append({'name': name, 'bytes': len(data), 'sha256': digest})
        if name.endswith('.zip'):
            check = regular_bytes(p.with_name(name + '.sha256'))[0].decode().strip()
            if check != f'{digest}  {name}':
                raise ValueError(f'checksum mismatch: {name}')
            with zipfile.ZipFile(io.BytesIO(data)) as z:
                bad = z.testzip()
                if bad or any(re.search(forbidden, item) or not item.startswith(SLUG + '/') or '..' in Path(item).parts for item in z.namelist()):
                    raise ValueError(f'corrupt or development-only release path: {name}')
                if name.endswith('-wporg.zip'):
                    main = z.read(f'{SLUG}/ffl-bridge.php').decode()
                    if any('class-ffl-bridge-updater.php' in item for item in z.namelist()) or re.search(r'^ \* Update URI:', main, re.M):
                        raise ValueError('WordPress.org package contains self-hosted updater')
    return manifest


def transferred_assets(args):
    manifest = verify_assets(args.source, args.workflow)
    previous = json.loads(regular_bytes(args.output / 'package.json')[0])
    expected = {'repository': PROFILE['repository'], 'source_sha': args.sha,
                'workflow': args.workflow, 'event': args.event, 'ref': args.ref,
                'stage': 'package', 'status': 'success', 'artifacts': manifest}
    if any(previous.get(key) != value for key, value in expected.items()):
        raise ValueError('transferred artifacts do not match the successful package receipt')
    return manifest


def package(args, receipt):
    dist = args.source / 'dist'
    # A clean source checkout is required; do not silently reuse stale assets.
    if dist.exists():
        raise ValueError('package stage requires a fresh checkout with no dist directory')
    if args.workflow == 'ci' and PROFILE['wporg']:
        command(['bash', 'tests/build-release-test.sh'], args.source)
        # Test script builds both targets; workflow subsequently builds the default.
        shutil.rmtree(dist, ignore_errors=True)
    if PROFILE['wporg'] and args.workflow in ('release', 'wporg'):
        if args.workflow == 'release':
            command(['bash', 'bin/build-release.sh', '--target=self-hosted'], args.source)
        command(['bash', 'bin/build-release.sh', '--target=wporg'], args.source)
    else:
        command(['bash', 'bin/build-release.sh'], args.source)
    receipt['artifacts'] = verify_assets(args.source, args.workflow)
    if args.workflow == 'wporg' and (args.source / '.wordpress-org').is_dir():
        for asset in ('banner-772x250.png', 'banner-1544x500.png', 'icon-128x128.png', 'icon-256x256.png'):
            if not (args.source / '.wordpress-org' / asset).is_file():
                raise ValueError(f'missing WordPress.org asset: {asset}')


def plugin_check(args, receipt):
    if args.workflow != 'wporg' or not PROFILE['wporg']:
        raise ValueError('Plugin Check is only applicable to WordPress.org')
    if not command(['node', '--version'], args.source, True).startswith('v24.'):
        raise ValueError('Plugin Check action requires Node 24')
    receipt['artifacts'] = transferred_assets(args)
    build = args.source / 'dist/wporg-build' / SLUG
    if not build.is_dir():
        with zipfile.ZipFile(next((args.source / 'dist').glob('*-wporg.zip'))) as archive:
            archive.extractall(build.parent)
    # Same latest core/env/plugin-check as pinned action; record runtime versions.
    with tempfile.TemporaryDirectory(prefix='ffl-wp-env-') as d:
        root = Path(d)
        (root / '.wp-env.json').write_text(json.dumps({'core': None, 'port': 8880, 'testsPort': 8881,
            'mappings': {f'wp-content/plugins/{SLUG}': str(build)}}))
        command(['npm', 'install', '--no-fund', '--no-save', '--prefix', d, '@wordpress/env'], root)
        env = str(root / 'node_modules/.bin/wp-env')
        try:
            for attempt in range(3):
                try:
                    command([env, 'start', '--update'], root, timeout=600)
                    command([env, 'run', 'cli', 'wp', 'cli', 'info'], root)
                    break
                except (subprocess.CalledProcessError, subprocess.TimeoutExpired):
                    if attempt == 2:
                        raise
            wp = [env, 'run', 'cli', 'wp']
            command(wp + ['plugin', 'install', 'plugin-check', '--activate'], root)
            dependencies = command(wp + ['plugin', 'get', SLUG, '--field=requires_plugins'], root, True)
            # wp-env may emit informational lines; only accept slug lists.
            dep_line = dependencies.splitlines()[-1] if dependencies else ''
            if dep_line:
                deps = dep_line.split(',')
                if not all(re.fullmatch(r'[a-z0-9-]+', dep.strip()) for dep in deps):
                    raise ValueError('cannot parse plugin dependency list')
                command(wp + ['plugin', 'install', '--activate'] + [dep.strip() for dep in deps], root)
            command(wp + ['plugin', 'activate', SLUG], root)
            receipt['runtime'] = {'core': command(wp + ['core', 'version'], root, True),
                'plugins': command(wp + ['plugin', 'list', '--format=json'], root, True),
                'wp_env': command([env, '--version'], root, True)}
            check = subprocess.run(wp + ['plugin', 'check', SLUG, '--format=json', '--include-experimental',
                f'--slug={SLUG}', '--exclude-files=.wp-env.json',
                '--require=./wp-content/plugins/plugin-check/cli.php'], cwd=root, text=True, stdout=subprocess.PIPE)
            results = check.stdout
            (args.output / 'plugin-check-results.txt').write_text(results)
            check.check_returncode()
            errors = []
            lines = results.splitlines()
            for i, line in enumerate(lines):
                if line.startswith('FILE: '):
                    for finding in json.loads(lines[i + 1]):
                        if finding['type'] == 'ERROR':
                            errors.append(finding)
            if errors:
                raise ValueError(f'Plugin Check reported {len(errors)} errors (warnings remain advisory)')
        finally:
            # Always tear down private wp-env containers; no SVN/GitHub credentials.
            command([env, 'cleanup', '--force'], root)


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--source', type=Path, required=True)
    p.add_argument('--sha', required=True)
    p.add_argument('--workflow', choices=['ci', 'release', 'wporg'], required=True)
    p.add_argument('--event', choices=['push', 'pull_request'], required=True)
    p.add_argument('--ref', required=True)
    p.add_argument('--stage', choices=['context', 'quality', 'package', 'verify', 'plugin-check', 'plan'], required=True)
    p.add_argument('--php', choices=['8.3', '8.4', '8.5'], default='8.3')
    p.add_argument('--output', type=Path, required=True)
    args = p.parse_args()
    args.source = args.source.resolve()
    args.output = args.output.resolve()
    args.output.mkdir(parents=True, exist_ok=True)
    receipt = {'schema': 1, 'repository': PROFILE['repository'], 'source_sha': args.sha,
        'workflow': args.workflow, 'event': args.event, 'ref': args.ref, 'stage': args.stage,
        'php_lane': args.php if args.stage == 'quality' else None, 'authority': 'github-actions',
        'shadow': True, 'started_at_epoch': int(time.time()), 'status': 'failure',
        'tooling_sha': os.environ.get('TOOLING_SHA', 'local-uncommitted'),
        'build_number': os.environ.get('BUILD_NUMBER', 'local')}
    rc = 1
    try:
        manifest = source_manifest(args)
        receipt['source_manifest_sha256'] = hashlib.sha256(json.dumps(manifest, sort_keys=True).encode()).hexdigest()
        receipt['source_integrity'] = 'original_commit_blob_bytes_and_modes'
        context(args, manifest)
        if args.stage == 'quality':
            quality(args, receipt)
        elif args.stage == 'package':
            package(args, receipt)
        elif args.stage in ('verify', 'plan'):
            receipt['artifacts'] = transferred_assets(args) if args.stage == 'plan' else verify_assets(args.source, args.workflow)
        elif args.stage == 'plugin-check':
            plugin_check(args, receipt)
        if args.stage == 'plan':
            if args.workflow == 'ci':
                raise ValueError('CI has no publication stage')
            receipt['publication'] = {'status': 'blocked_shadow' if args.event == 'push' else 'not_applicable_pull_request',
                'eligible_tag_event': args.event == 'push',
                'tag': args.ref.removeprefix('refs/tags/') if args.event == 'push' else None,
                'github_release_repository': PROFILE['repository'] if args.workflow == 'release' else None,
                'github_release_title': f'FFL Bridge for WooCommerce {args.ref.removeprefix("refs/tags/v")}' if args.workflow == 'release' else None,
                'generate_notes': args.workflow == 'release', 'verify_tag': args.workflow == 'release',
                'svn_url': f'https://plugins.svn.wordpress.org/{SLUG}/' if args.workflow == 'wporg' else None,
                'svn_paths': ['trunk/', 'tags/' + args.ref.removeprefix('refs/tags/v') + '/', 'assets/'] if args.workflow == 'wporg' and args.event == 'push' else [],
                'required_environment': 'wordpress-org' if args.workflow == 'wporg' else None,
                'missing_svn_credentials_semantics': 'skip_with_notice',
                'self_hosted_manifest': 'separate human publication; unchanged'}
        # Build/dependency scripts can mutate source: verify integrity again before success.
        context(args, manifest)
        receipt['status'] = 'success'
        rc = 0
    except (ValueError, OSError, subprocess.SubprocessError, KeyError, IndexError, json.JSONDecodeError, zipfile.BadZipFile) as e:
        receipt['error'] = str(e)
        print(f'Shadow stage failed: {e}', file=sys.stderr)
    finally:
        receipt['finished_at_epoch'] = int(time.time())
        lane = '-' + args.php if args.stage == 'quality' else ''
        write_receipt(args.output / f'{args.stage}{lane}.json', json.dumps(receipt, indent=2, sort_keys=True) + '\n')
    return rc


if __name__ == '__main__':
    sys.exit(main())
