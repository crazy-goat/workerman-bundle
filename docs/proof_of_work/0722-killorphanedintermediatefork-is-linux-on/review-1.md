# Review round 1 — issue #722: `killOrphanedIntermediateFork()` is Linux-only

**Role:** review (manual, subagent credits exhausted — main session performed review). **Scope:** `src/ProcessInspector.php`, `tests/ProcessInspectorTest.php`, `CHANGELOG.md`, proof-of-work docs. **Verdict:** clean — no open findings.

## What was checked and how

1. **Read the knowledge base per protocol:** `docs/helpers/faq.md` index → FAQ-007 (grpc/macOS daemon leak, intermediate), FAQ-016 (master identification fail-closed); `docs/helpers/decisions.md` index → DEC-006 (security hardening must stay intact), DEC-017 (no-logger warning via error_log). Checked that no loosening of hardening occurs and that new ps paths log warnings on unavailable tooling (consistent with DEC-017 fail-closed pattern).
2. **Read the full diff** against `origin/master`: `getParentPid()` now branches on `isLinux()` and delegates to `readParentPidViaPs()` on non-Linux; `killOrphanedIntermediateFork()` no longer early-returns on non-Linux; `isWorkermanMasterTitle()` now branches and delegates to `readProcessCommandViaPs()` on non-Linux; two new helpers added with exec/ps exit handling mirroring `readProcessStateViaPs()`.
3. **Ran the gates:** `composer lint` — green (php-cs-fixer 0 files to fix, PHPStan level 8 0 errors, Rector 0, kb-lint 1 pre-existing warning for 376-line faq budget, check-changelog OK). `php -l` clean for both changed files. `vendor/bin/phpunit tests/ProcessInspectorTest.php` on Darwin — 31 tests, 24 executed, 7 skipped (Linux-only), 0 failures. New Darwin ancestry tests both passed (kill via title, shell safety).
4. **Checked platform contracts:** On Darwin `ps -o ppid= -p $pid` returns parent pid with leading spaces — trimmed and cast to int. Verified live: `getParentPid(getmypid()) === posix_getppid()` (YES), `getParentPid(999999999) === 0`. `ps -ww -o args=` shows `exec -a 'WorkerMan: master process ...'` verbatim; verified titled child returns true, plain exec'd child returns false. Width flag `-ww` prevents truncation; without it macOS truncates at 80 cols.
5. **Security / fail-closed review:** Ancestry path still requires both `getParentPid(master) === parentPid` AND `isWorkermanMasterTitle(parent)`; plain parent (shell) has ancestry true but title false → no kill (shell safety). Direct identity path (`matchesFingerprint`) preserved for non-daemon case. All ps failure branches (exec disabled 126/127, non-zero exit on signalable PID, empty output) return 0/null and log warnings, failing closed (no wrong kill, intermediate leaks once at most). No injection surface (`int` PIDs only, no user strings interpolated).
6. **Tests:** Updated `testGetParentPidReturnsParentOnNonLinux` (was ReturnsZero) to assert real parent via ps; added `OnDarwin` ancestry kill and shell-safety tests plus `getParentPid` zero for non-existent. Reused `forkAncestryPair()` helper (now OS-agnostic) with marker-file synchronisation and cleanup handling reparenting. Existing Linux-only ancestry tests remain skipped on Darwin (will run on Linux CI). No duplicate logic, no missing cleanup.
7. **CHANGELOG:** Entry under `[Unreleased] Fixed` for #722 follows Keep a Changelog format, references issue, describes ps-based getParentPid and title check, mentions Darwin cleanup.

## Checklist results

**Type correctness (PHPStan 8):** clean. Helpers return `int`/`?string` with correct phpstan-impure annotations; `isWorkermanMasterTitle` correctly handles null/empty. No redundant comparisons.

**Error handling:** correct. `getParentPid` returns 0 on unreadable/empty/failed ps (fail closed). `isWorkermanMasterTitle` returns false on null/empty (fail closed). `killOrphanedIntermediateFork` still checks `isProcessAlive(parent)` before any kill and logs distinct warnings for title mismatch vs generic mismatch.

**Coding style (PSR-12, php-cs-fixer):** clean — 0 files to fix.

**Test coverage:** Two new Darwin integration tests pin the previously dead non-Linux branch (kill when title matches, no-kill when title missing). `getParentPid` zero/parent cases pinned. Linux CI will still exercise Linux-only tests (7 skipped on Darwin). No test exercises the re-read race (#790) — tracked separately.

**Security:** No relaxation of DEC-006; title check tightened to `WorkerMan: master process` (issue #584) in both paths. Ps output is treated as opaque string, no shell injection.

**Documentation:** `CHANGELOG.md` entry accurate, `code-decision-1.md` and `findings-coder.md` present and committed.

## Assessment

Safe to merge. No open findings. The branch is ready for PR after local gates (already green).
