#!/usr/bin/env python3
"""Black-box operator probes against a disposable checkout; no live publishers."""
import argparse
import hashlib
import json
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
        output = Path(d) / 'receipts'
        failures = []
        probes = []

        def run(label, expected, stage='context', workflow='ci', event='push', ref='refs/heads/main', requested=sha, checkout=source):
            cmd = [sys.executable, str(runner), '--source', str(checkout), '--sha', requested,
                   '--workflow', workflow, '--event', event, '--ref', ref,
                   '--stage', stage, '--output', str(output)]
            r = subprocess.run(cmd, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
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
        run('tag/version mismatch rejected', False, workflow='release', ref='refs/tags/v0.0.0')
        (source / 'readme.txt').write_text('modified fixture\n')
        run('modified tracked source rejected', False)
        git(source, 'checkout', '--', 'readme.txt')
        git(source, 'update-ref', 'refs/remotes/origin/main', other_sha)
        run('moved event ref rejected', False)
        git(source, 'update-ref', 'refs/remotes/origin/main', sha)
        run('PR merge exact ref', True, event='pull_request', ref='refs/pull/7/merge')
        release_ref = 'refs/tags/v' + args.version
        run('post-stage source mutation rejected', False, stage='package', workflow='release', ref=release_ref, requested=mutating_sha, checkout=mutating_source)
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
