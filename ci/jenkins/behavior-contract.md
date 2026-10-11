# Shadow behavior contract

Target: CLI `python3 ci/jenkins/shadow.py`, using a fresh isolated Git fixture with source SHA and matching remote/ref. The operator may read generated receipts, logs and ZIPs; do not inspect implementation during the validation pass.

1. An exact lowercase 40-character SHA matching checkout/ref succeeds at `context`; short SHA, moved ref and modified tracked source fail with a failure receipt. A stage that mutates tracked source after producing valid output must fail before success.
2. `package` creates real canonical ZIP/checksum files with valid CRC/SHA256, excludes Jenkins/CI/development material, and refuses stale `dist`. The public repository supports both release targets; prelaunch supports one.
3. `verify` rejects unexpected/missing/tampered assets. `plan` requires the successful package receipt with matching source SHA and exact artifact manifest.
4. Release tag/header mismatch fails before creating artifacts. A valid tag plan names the original repository and same tag/title; public WordPress.org tag plan names the original SVN slug/trunk/tag/assets. Plans perform no publication.
5. Quality runs the PHP lane, Composer strict validation/install/audit/check and JS syntax. PHP runtime mismatch or missing runtime fails explicitly. Live PHP 8.3/8.4/8.5 proof is blocked if those runtimes are unavailable.
6. Public Plugin Check uses a disposable environment, checks experimental results and fails on errors. Runtime parity is blocked until Node 24/Docker is available. Private WordPress.org context is rejected.
7. Actions remain authoritative. Live Jenkins linter/execution, concurrency/abort/timeout, statuses, retention, release/SVN mutation and security changes are blocked or out of scope during local preparation.

Anti-cheat probes: invalid SHA, wrong event/ref, tag mismatch, dirty tracked source, second package attempt, byte corruption, extra archive, receipt SHA mismatch and altered transfer manifest. Inspect actual ZIP member names and checksum bytes, not success text alone.
