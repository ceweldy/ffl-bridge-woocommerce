#!/usr/bin/env python3
"""Black-box operator probes against a disposable checkout; no live publishers."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import zipfile


def git(root, *args):
    return subprocess.check_output(['git', '-C', str(root), *args], text=True).strip()


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--repository', type=Path, required=True)
    p.add_argument('--version', required=True, help='Fixture plugin version from the operator contract')
    p.add_argument('--public', action='store_true')
    args = p.parse_args()
    root = args.repository.resolve()
    runner = root / 'ci/jenkins/shadow.py'
    with tempfile.TemporaryDirectory(prefix='ffl-shadow-probes-') as d:
        # Prepare the allowed source fixture before the source-blind pass begins.
        source = Path(d) / 'source'
        source.mkdir()
        paths = git(root, 'ls-files').splitlines()
        paths += ['Jenkinsfile'] + [str(x.relative_to(root)) for x in (root / 'ci/jenkins').glob('*') if x.is_file()]
        for name in set(paths):
            target = source / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(root / name, target)
            shutil.copymode(root / name, target)
        git(source, 'init', '-q', '-b', 'main')
        git(source, 'add', '.')
        git(source, '-c', 'user.name=Shadow Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'Fixture')
        sha = git(source, 'rev-parse', 'HEAD')
        git(source, 'update-ref', 'refs/remotes/origin/main', sha)
        git(source, 'update-ref', 'refs/pull/7/merge', sha)
        git(source, 'tag', 'v' + args.version)
        git(source, 'tag', 'v0.0.0')
        git(source, '-c', 'user.name=Shadow Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '--allow-empty', '-qm', 'Moved ref fixture')
        other_sha = git(source, 'rev-parse', 'HEAD')
        git(source, 'reset', '--hard', sha)
        # Allowed anti-cheat fixture: its build creates real bytes then mutates tracked source.
        mutating_source = Path(d) / 'mutating-source'
        subprocess.run(['git', 'clone', '--quiet', '--no-local', str(source), str(mutating_source)], check=True)
        with (mutating_source / 'bin/build-release.sh').open('a') as build:
            build.write('\nprintf "\\n" >> "${ROOT_DIR}/readme.txt"\n')
        git(mutating_source, 'add', 'bin/build-release.sh')
        git(mutating_source, '-c', 'user.name=Shadow Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'Mutating build fixture')
        mutating_sha = git(mutating_source, 'rev-parse', 'HEAD')
        git(mutating_source, 'tag', '-f', 'v' + args.version)
        masked_sources = []
        for flag in ('assume-unchanged', 'skip-worktree'):
            masked = Path(d) / flag
            subprocess.run(['git', 'clone', '--quiet', '--no-local', str(source), str(masked)], check=True)
            with (masked / 'bin/build-release.sh').open('a') as build:
                build.write('\ngit -C "${ROOT_DIR}" update-index --' + flag + ' readme.txt\nprintf "\\n" >> "${ROOT_DIR}/readme.txt"\n')
            git(masked, 'add', 'bin/build-release.sh')
            git(masked, '-c', 'user.name=Shadow Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'Masked mutation fixture')
            masked_sha = git(masked, 'rev-parse', 'HEAD')
            git(masked, 'tag', '-f', 'v' + args.version)
            masked_sources.append((flag, masked, masked_sha))
        guard = Path(d) / 'archive-guard.py'
        # Capture the trusted inline guard before the source-blind validation pass.
        inline = (root / 'Jenkinsfile').read_text().split('# ffl archive guard begin\n', 1)[1].split('# ffl archive guard end', 1)[0]
        guard.write_text(inline)
        archive_fixture = Path(d) / 'archive-fixture'
        (archive_fixture / 'receipts').mkdir(parents=True)
        (archive_fixture / 'source/dist').mkdir(parents=True)
        (archive_fixture / 'receipts/regular.json').write_text('{}')
        (archive_fixture / 'source/dist/regular.zip').write_bytes(b'regular file fixture')
        original_readme = (source / 'readme.txt').read_bytes()
        output = Path(d) / 'receipts'
        failures = []
        probes = []

        def run(label, expected, stage='context', workflow='ci', event='push', ref='refs/heads/main', requested=sha, checkout=source):
            cmd = [sys.executable, str(runner), '--source', str(checkout), '--sha', requested,
                   '--workflow', workflow, '--event', event, '--ref', ref,
                   '--stage', stage, '--output', str(output)]
            r = subprocess.run(cmd, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=30)
            lane = '-8.3' if stage == 'quality' else ''
            receipt = json.loads((output / (stage + lane + '.json')).read_text())
            success = r.returncode == 0
            passed = success == expected and receipt['status'] == ('success' if expected else 'failure')
            probes.append({'probe': label, 'status': 'pass' if passed else 'fail', 'exit_code': r.returncode,
                           'receipt_status': receipt['status'], 'error': receipt.get('error')})
            if not passed:
                failures.append(label + ': ' + r.stdout)
            return receipt

        # From this point interact only with CLI, Git operator surfaces and generated artifacts.
        run('exact SHA context', True)
        run('short SHA rejected', False, requested=sha[:7])
        run('wrong event/ref rejected', False, ref='refs/heads/feature')
        extra_tracked = source / 'extra-tracked.txt'
        extra_tracked.write_text('index-only additional fixture')
        git(source, 'add', 'extra-tracked.txt')
        run('additional tracked index path rejected', False)
        git(source, 'reset', '--quiet', 'HEAD', '--', 'extra-tracked.txt')
        extra_tracked.unlink()
        run('tag/version mismatch rejected', False, workflow='release', ref='refs/tags/v0.0.0')
        (source / 'readme.txt').write_text('modified fixture\n')
        run('modified tracked source rejected', False)
        git(source, 'checkout', '--', 'readme.txt')
        for flag in ('assume-unchanged', 'skip-worktree'):
            git(source, 'update-index', '--' + flag, 'readme.txt')
            (source / 'readme.txt').write_bytes(b'X' + original_readme[1:])
            assert subprocess.run(['git', '-C', str(source), 'diff', '--quiet', 'HEAD', '--']).returncode == 0
            run('pre-stage ' + flag + ' mask rejected', False)
            git(source, 'update-index', '--no-' + flag, 'readme.txt')
            git(source, 'checkout', '--', 'readme.txt')
        git(source, 'config', 'core.filemode', 'false')
        (source / 'readme.txt').chmod(0o755)
        assert subprocess.run(['git', '-C', str(source), 'diff', '--quiet', 'HEAD', '--']).returncode == 0
        run('core.filemode masked executable change rejected', False)
        (source / 'readme.txt').chmod(0o644)
        git(source, 'config', 'core.filemode', 'true')
        (source / 'readme.txt').unlink()
        os.mkfifo(source / 'readme.txt')
        run('tracked FIFO rejected without blocking', False)
        (source / 'readme.txt').unlink()
        git(source, 'checkout', '--', 'readme.txt')
        output.mkdir(exist_ok=True)
        (output / 'context.json').unlink()
        os.mkfifo(output / 'context.json')
        run('receipt target FIFO atomically replaced', True)
        git(source, 'update-ref', 'refs/remotes/origin/main', other_sha)
        run('moved event ref rejected', False)
        git(source, 'update-ref', 'refs/remotes/origin/main', sha)
        run('PR merge exact ref', True, event='pull_request', ref='refs/pull/7/merge')
        release_ref = 'refs/tags/v' + args.version
        run('post-stage source mutation rejected', False, stage='package', workflow='release', ref=release_ref, requested=mutating_sha, checkout=mutating_source)
        for flag, masked, masked_sha in masked_sources:
            mutation = run('post-stage ' + flag + ' mask rejected', False, stage='package', workflow='release', ref=release_ref, requested=masked_sha, checkout=masked)
            assert mutation.get('artifacts'), 'failure must occur after real artifact creation'
            assert subprocess.run(['git', '-C', str(masked), 'diff', '--quiet', 'HEAD', '--']).returncode == 0
        receipt = run('real release package', True, stage='package', workflow='release', ref=release_ref)
        names = {a['name'] for a in receipt['artifacts']}
        expected_count = 4 if args.public else 2
        assert len(names) == expected_count, names
        for archive in (source / 'dist').glob('*.zip'):
            with zipfile.ZipFile(archive) as z:
                assert z.testzip() is None
                assert all('/ci/' not in x and not x.endswith('/Jenkinsfile') for x in z.namelist())
            assert hashlib.sha256(archive.read_bytes()).hexdigest() in archive.with_name(archive.name + '.sha256').read_text()
        run('stale dist rejected', False, stage='package', workflow='release', ref=release_ref)
        # The failed stale attempt overwrites package receipt; restore valid producer evidence.
        shutil.rmtree(source / 'dist')
        receipt = run('fresh package retry', True, stage='package', workflow='release', ref=release_ref)
        plan = run('immutable transfer plan', True, stage='plan', workflow='release', ref=release_ref)
        assert plan['publication']['status'] == 'blocked_shadow'
        assert plan['publication']['tag'] == 'v' + args.version
        expected_repo = 'ceweldy/ffl-bridge-woocommerce' + ('' if args.public else '-prelaunch-private')
        assert plan['publication']['github_release_repository'] == expected_repo
        archive = next((source / 'dist').glob('*.zip'))
        original = archive.read_bytes()
        archive.write_bytes(original + b'corruption')
        run('tampered byte checksum rejected', False, stage='verify', workflow='release', ref=release_ref)
        archive.write_bytes(original)
        archive.unlink()
        archive.symlink_to(archive.with_name(archive.name + '.sha256'))
        run('artifact symlink rejected', False, stage='verify', workflow='release', ref=release_ref)
        archive.unlink()
        os.mkfifo(archive)
        run('artifact FIFO rejected without blocking', False, stage='verify', workflow='release', ref=release_ref)
        archive.unlink()
        archive.write_bytes(original)
        extra = source / 'dist/unexpected.zip'
        extra.write_bytes(original)
        run('extra archive rejected', False, stage='verify', workflow='release', ref=release_ref)
        extra.unlink()
        producer = output / 'package.json'
        original_receipt = producer.read_text()
        bad = json.loads(original_receipt)
        bad['source_sha'] = '0' * 40
        producer.write_text(json.dumps(bad))
        run('receipt SHA mismatch rejected', False, stage='plan', workflow='release', ref=release_ref)
        producer.write_text(original_receipt)
        bad = json.loads(original_receipt)
        bad['artifacts'][0]['sha256'] = '0' * 64
        producer.write_text(json.dumps(bad))
        run('receipt artifact manifest mismatch rejected', False, stage='plan', workflow='release', ref=release_ref)
        producer.write_text(original_receipt)
        bad = json.loads(original_receipt)
        bad['workflow'] = 'ci'
        producer.write_text(json.dumps(bad))
        run('receipt workflow mismatch rejected', False, stage='plan', workflow='release', ref=release_ref)
        producer.write_text(original_receipt)
        if args.public:
            shutil.rmtree(source / 'dist')
            run('public directory package', True, stage='package', workflow='wporg', ref=release_ref)
            plan = run('directory destination plan', True, stage='plan', workflow='wporg', ref=release_ref)
            assert plan['publication']['svn_paths'] == ['trunk/', 'tags/' + args.version + '/', 'assets/']
            assert plan['publication']['svn_url'] == 'https://plugins.svn.wordpress.org/ffl-bridge-for-woocommerce/'
            shutil.rmtree(source / 'dist')
            run('PR directory package', True, stage='package', workflow='wporg', event='pull_request', ref='refs/pull/7/merge')
            plan = run('PR does not publish', True, stage='plan', workflow='wporg', event='pull_request', ref='refs/pull/7/merge')
            assert plan['publication']['status'] == 'not_applicable_pull_request'
            assert plan['publication']['svn_paths'] == []
        else:
            run('private directory path rejected', False, workflow='wporg', ref=release_ref)
        shutil.rmtree(source / 'dist')
        first = run('CI package', True, stage='package')
        shutil.rmtree(source / 'dist')
        second = run('same SHA deterministic CI package', True, stage='package')
        assert first['artifacts'] == second['artifacts']
        def archive_probe(label, expected):
            r = subprocess.run([sys.executable, str(guard)], cwd=archive_fixture, text=True,
                               stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=5)
            passed = (r.returncode == 0) == expected
            probes.append({'probe': label, 'status': 'pass' if passed else 'fail', 'exit_code': r.returncode})
            if not passed:
                failures.append(label + ': ' + r.stdout)
        archive_probe('final archive regular files accepted', True)
        special = archive_fixture / 'receipts/special.json'
        special.symlink_to(archive_fixture / 'source/dist/regular.zip')
        archive_probe('final archive receipt symlink rejected', False)
        special.unlink()
        os.mkfifo(special)
        archive_probe('final archive receipt FIFO rejected without blocking', False)
        special.unlink()
        special = archive_fixture / 'source/dist/special.zip'
        os.mkfifo(special)
        archive_probe('final archive ZIP FIFO rejected without blocking', False)
        special.unlink()
        original_dist = archive_fixture / 'source/renamed-dist'
        (archive_fixture / 'source/dist').rename(original_dist)
        (archive_fixture / 'source/dist').symlink_to(original_dist, target_is_directory=True)
        archive_probe('final archive linked directory rejected', False)
        print(json.dumps({'repository': expected_repo, 'fixture_sha': sha, 'probes': probes,
                          'runtime_clauses': {'php_matrix': 'not_exercised_by_artifact_probes',
                           'plugin_check': 'not_exercised_by_artifact_probes' if args.public else 'out_of_scope',
                           'live_jenkins': 'not_exercised_by_artifact_probes', 'publication': 'out_of_scope_shadow_only'}}, indent=2))
        if failures:
            print('\n'.join(failures), file=sys.stderr)
            return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
