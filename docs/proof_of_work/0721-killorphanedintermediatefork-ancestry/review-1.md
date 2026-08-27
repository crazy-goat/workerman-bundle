# Review round 1 — issue #721: `killOrphanedIntermediateFork()` fingerprint path never fires

**Role:** review-critical. **Commit:** (working tree, before commit). **Scope:** `src/ProcessInspector.php`, `tests/ProcessInspectorTest.php`, `CHANGELOG.md`, proof-of-work docs.
**Verdict:** correct in core logic and well tested for the intended Linux platform; one low-severity nit and one documentation note. Details in `findings-review.md`.

## What was checked and how

1. **Read the knowledge base per protocol:** `docs/helpers/faq.md` index → FAQ-007 (grpc/macOS/daemon, intermediate leak), FAQ-016 (master identification fail-closed); `docs/helpers/decisions.md` index → DEC-006 (master-identification hardening must stay intact), DEC-013 (fail-open vs fail-closed).
2. **Read the full diff** against `origin/master` and the surrounding code (`ServerManager::stop()`, `MasterFingerprint`, `getParentPid()`, `isWorkermanMasterTitle()` helper, `forkAncestryPair()` test helper).
3. **Ran the gates:** `composer lint` — green (php-cs-fixer, PHPStan level 8, Rector, kb-lint with pre-existing 376-line budget warning, check-changelog). `vendor/bin/phpunit tests/ProcessInspectorTest.php` on Darwin — 28 tests, 21 executed, 7 skipped (all Linux-only including the 2 new ancestry tests), 0 failures. `php -l` clean for both changed files.
4. **Checked platform contracts:** On Linux a zombie retains `/proc/$pid/status` with `PPid` until parent reaps (hung intermediate never reaps), so `getParentPid(fingerprint->pid)` after `waitForProcessToStop()` should still return the intermediate. On Darwin the early `if (!isLinux()) return;` still makes the whole method a no-op — #722's `ps -o ppid=` is correctly not added here.
5. **Security / fail-closed review:** Direct identity path preserved; ancestry path adds `isWorkermanMasterTitle()` title check so a non-Workerman parent (shell in non-daemon mode) is not killed even though `getParentPid(master) === parentPid` holds. Unreadable `/proc` paths (hidepid, wrong owner) fail closed (return `false` → no kill, warning logged). No injection surface (`int` PIDs only).
6. **Tests:** Two new Linux-only integration tests pin the two ancestry branches (kill when title matches, no-kill when title missing) via a real `parent → child` hierarchy built with a temp script exec'd with `WorkerMan: master process` title (`exec -a`) and `Wait::until` on marker files. Cleanup handles reparenting (master becomes child of init after intermediate death) and unlinks temp files. Existing 4 kill tests still pass (skipped on Darwin, will run on Linux CI).

## Checklist results

**Type correctness (PHPStan 8):** clean. Removed the redundant `&& $actualParent !== 0` that phpstan flagged as always-true after the first fix. `isWorkermanMasterTitle(): bool` correctly handles `is_readable` → `false` and `file_get_contents` → `false|''` cases.

**Error handling:** correct. `getParentPid()` returns `0` on unreadable `/proc` or when master already reaped → ancestry check fails closed (intermediate leaks once, no wrong kill). `isWorkermanMasterTitle()` fails closed on unreadable/empty cmdline. Both ancestry paths log distinct warnings.

**Coding style (PSR-12, php-cs-fixer):** clean — 0 files to fix.

**Test coverage:** two new tests cover the previously dead branch. They are Linux-only, so a Darwin dev never exercises them locally; the fallback `TestProcessInspector` mock suggestion in `findings-coder.md` would close that gap but is not required for CI.

**Security:** no relaxation of DEC-006; the title check is tightened to `"WorkerMan: master process"` (issue #584), not the old `"WorkerMan"` substring. Ancestry verification does not trust the fingerprint's `startTime`/`uid` post-mortem because `isMasterRunning()` already verified the fingerprint while the master was alive.

**Documentation:** `CHANGELOG.md` entry under `[Unreleased] Fixed` follows Keep a Changelog format and references #721.

## Assessment

Safe to merge after the single nit is addressed (R1). No second review round needed — the nit is local to log message wording and does not affect behaviour.
