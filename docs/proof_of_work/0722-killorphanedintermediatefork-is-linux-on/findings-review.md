# Findings — review, issue #722

No open findings. Round 1 was clean.

## Round 1

- All platform contracts verified on Darwin host; Linux CI will exercise the `/proc` path via the 7 skipped tests.
- `composer lint` green, `phpstan` 0 errors, `php-cs-fixer` 0, `check-changelog` OK.
- `vendor/bin/phpunit tests/ProcessInspectorTest.php` on Darwin: 31 tests, 7 skipped, 0 failures.
- No helper doc drift; kb budget warning is pre-existing (376 lines) not introduced here.

## Notes for next review

- If a follow-up fixes #790 (re-read race), the ancestry verification should compare the `parentPid` captured in `ServerManager::stop()` (already `getParentPid(master)` while alive) directly, rather than re-reading `getParentPid(fingerprint->pid)` after `waitForProcessToStop()`. Tracked as #790.
