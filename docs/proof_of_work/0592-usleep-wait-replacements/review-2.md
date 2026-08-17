# Review — Round 2 — #592 usleep/wait replacements

**Branch:** `test/issue-592-43-fixed-duration-usleep-waits-plus-slee`
**Diff:** `master...HEAD` — 2 commits (b3997e2 + 4a821c9), 21 source files
**Fix commit under review:** `4a821c9` — 4 source files changed
  (bin/wait-for-ports.php, tests/ServerManagerTest.php,
  tests/ControlByteWorkerDosE2ETest.php, tests/BinDirectoryTest.php)
**Scope:** test/deps/tooling only; no `src/` changes in either commit.

## 1. Earlier findings — revisit

| ID | Verdict | Evidence |
|----|---------|----------|
| R1-F1 (medium) | **Fixed** | `waitForChildReadyOrKill(string $marker, int $pid)` added at ServerManagerTest.php:1273; wraps `waitForChildReady` in try/catch `\PHPUnit\Framework\AssertionFailedError` → `killChildBlocking($pid)` → re-throw. All three signal-handler helpers call it (lines 1094, 1208, 1241). No double-kill race: `killChildBlocking` (line 1291) checks `isAlive($pid)` via `posix_kill($pid, 0)` before sending SIGKILL + `pcntl_waitpid`; on the catch path the child is genuinely running (`for(;;) { usleep(100_000); }` that never touched the marker in 5s). The test method's `try/finally { killChildBlocking($pid); }` is never reached on this failure path because the exception propagates from the helper **before** `$pid` is assigned (the helper call is outside the try block — verified at lines 259/263, 403/408, 468/473), so `killChildBlocking` is called exactly once. No fourth straggler: `forkSleepingChild` (line 1016) has no readiness wait; `forkMasterLikeChild` (line 1040) and `forkChildWithMasterTitle` (line 1108) use `waitForFile` which does not assert the return value and never throws — both always return `$pid`. |
| R1-F2 (low) | **Fixed** | bin/wait-for-ports.php:61 now passes `(int) ceil($timeoutSeconds)` to `Wait::until`. Verified: `--timeout=1` → "within 1 seconds", elapsed 1.27s (ceil(1)=1 + backoff tail); `--timeout=1.5` → "within 1.5 seconds", elapsed 2.30s (ceil(1.5)=2 + backoff tail). Wait is always >= what the message claims. |
| R1-F3 (low) | **Fixed** | ControlByteWorkerDosE2ETest.php:255 adds `if ((int) \trim((string) \file_get_contents(.../worker.pid)) <= 0) return false;` after the two `is_file` guards. Handles `file_get_contents` returning `false` (TOCTOU delete between is_file and read): `(string) false = ""`, `trim("") = ""`, `(int) "" = 0`, `0 <= 0` → `return false` (wait continues). If the PID file stays empty, the wait times out into the existing "Worker did not become ready within 15s" fail(). |
| R1-F4 (nit) | **Fixed** | `$argc = $_SERVER['argc'] ?? 0;` removed from bin/wait-for-ports.php. The arg loop uses `array_slice($argv, 1)` and checks `$ports === []`. |
| R1-F5 (nit) | **Fixed** | `testWaitForPortsScriptExistsAndIsExecutable` → `testWaitForPortsScriptExists` at BinDirectoryTest.php:81. No `is_executable()` assertion added (script runs via `php bin/...`). |

## 2. Overall verdict

**Approve — no new findings above nit level.** The fix round is clean.
All five R1 findings are resolved with minimal, correct changes. The
`waitForChildReadyOrKill` helper (R1-F1) is the highest-risk change and
it is sound: the try/catch–kill–rethrow pattern is correct, the catch
type (`AssertionFailedError`) is the only throwable from `waitForChildReady`
(`Wait::until` returns bool, `@unlink` suppresses errors, `assertTrue`
throws `AssertionFailedError`), and `killChildBlocking`'s `isAlive`
guard prevents double-kill. The ceil() change (R1-F2) produces a wait
that is always >= the error message's claim — the residual cosmetic
discrepancy (message says 1.5s, waited 2s) is deliberate and documented.
The PID > 0 guard (R1-F3) correctly handles the `false`-from-`file_get_contents`
edge case. No `src/` changes, no BC breaks, no security surface, no
coverage-floor weakening.

## 3. New findings

No new findings at medium or above. One nit-level observation:

### R2-N1 | bin/wait-for-ports.php:61,67 | Error message prints the float while the wait uses ceil()→int | nit

**Evidence:** After the R1-F2 fix, `Wait::until` receives
`(int) ceil($timeoutSeconds)` (line 61), but the error message prints
the original float `$timeoutSeconds` (line 67). For `--timeout=1.5`:
the message says "within 1.5 seconds" but the actual wait is 2 seconds
(plus backoff overshoot → ~2.3s measured). The wait is always >= the
message's claim (ceil guarantees this), so the message is not misleading
in the harmful direction — it under-reports the actual wait duration.

**Impact:** Cosmetic. A user who times the script manually may notice the
discrepancy, but the message faithfully reports the timeout they
requested, not the implementation's effective integer. This is the
deliberate design choice documented in code-decision-2.md and
findings-coder.md item 7.

**Smallest safe fix (if desired):** Print `(int) ceil($timeoutSeconds)`
in the error message, or leave as-is (current behavior is the honest
direction).

**Check that would catch it:** None — this is a deliberate UX/cosmetics
choice, not a defect.

## 4. Candidate knowledge-base entries

**Candidate 1 (from R1, still relevant): Fork-helper readiness markers must clean up the child on failure**
- **Tags:** tests, process
- **Trigger:** "adding a readiness wait inside a fork helper"
- **Paragraph:** When a fork helper waits for a child-side readiness
  marker (file touch) **inside the helper** (before returning the PID),
  a failure of that wait orphans the child: the test method's
  `try/finally { killChildBlocking($pid); }` is never entered because
  the exception propagates from the helper before `$pid` is assigned
  (the helper call is outside the try block). Wrap the readiness wait
  in `waitForChildReadyOrKill($marker, $pid)` — a try/catch that kills
  the child via `killChildBlocking` (which guards with `isAlive` → no
  double-kill) before re-throwing. This pattern was introduced in #592
  when `usleep(200_000)` was replaced with `Wait::until()` polling on
  a marker file. Note: helpers that use `waitForFile` (non-throwing —
  does not assert the return value) do not need this wrapper because
  they always return `$pid`.

No new candidate entries beyond the R1 candidate (which is still valid
and now verified).

## 5. Remaining risk areas checked clean

| Area | Verdict | Evidence |
|------|---------|----------|
| R1-F1 double-kill race | ✅ clean | `killChildBlocking` (line 1291) checks `isAlive($pid)` before SIGKILL; on the catch path the child is genuinely running; the test method's finally is not reached (helper call is outside try block). Single kill, single reap. |
| R1-F1 catch type completeness | ✅ clean | `waitForChildReady` can only throw `AssertionFailedError` (from `assertTrue`); `Wait::until` returns bool (validated: throws `InvalidArgumentException` only for negative timeout / invalid delays, none applicable with `$timeoutSeconds = 5`); `@unlink` suppresses errors. |
| R1-F1 fourth straggler | ✅ clean | All 6 fork helpers accounted for: 3 use `waitForChildReadyOrKill` (signal handlers), 2 use `waitForFile` (non-throwing, exec'd children self-clean on exec failure), 1 has no wait (`forkSleepingChild`). |
| R1-F2 ceil() with integer timeout | ✅ clean | `ceil(1.0) = 1`, `(int) 1 = 1`. BinDirectoryTest timeout test uses `--timeout=1`; verified passing (3 tests, 5 assertions). |
| R1-F2 ceil() with zero timeout | ✅ clean (edge) | `--timeout=0` → `ceil(0) = 0` → `Wait::until($check, 0)` checks once, returns false immediately. Degenerate but valid; same as pre-fix `(int) 0`. Negative values rejected by regex (`\d+` only). |
| R1-F3 false-from-file_get_contents | ✅ clean | If file is deleted between `is_file` and `file_get_contents`, `(string) false = ""` → `(int) "" = 0` → `0 <= 0` → `return false` (wait continues). No TypeError (string cast applied first). |
| R1-F3 final unconditional read TOCTOU | ✅ clean (not real) | The post-`Wait::until` unconditional `file_get_contents` could in theory read a different value than the last poll. For PID files this requires the worker to delete and rewrite its PID file in the sub-millisecond window — not a real failure mode. The condition guarantees > 0 on the last successful poll. |
| `waitForChildReadyOrKill` called with valid PID | ✅ clean | `pcntl_fork` returns -1 (markTestSkipped) or 0 (child branch, which doesn't call the helper). Parent always has `$pid > 0` when calling the helper. |
| `@unlink($marker)` in `waitForChildReady` | ✅ clean | Called before `assertTrue`, so the marker is cleaned up whether the wait succeeded or failed. `waitForChildReadyOrKill`'s catch doesn't need to unlink. |
| `stopWorker()` Wait::until replacement | ✅ clean | Original: hand-rolled 3s loop with `usleep(50_000)`. New: `Wait::until(fn => $proc === null \|\| !proc_get_status($proc)['running'], 3)`. Same semantics: poll until exit or timeout, then SIGKILL fallback. |
| `startWorker()` process-exit detection | ✅ clean | If the worker exits after becoming ready but before the method returns, the post-`Wait::until` `proc_get_status($this->process)['running'] === false` check catches it and calls `fail()`. Equivalent to the original `break` + fall-through-to-fail. |
| `composer.json` script change | ✅ clean | `sleep 1` → `php bin/wait-for-ports.php 8888 9999` in both `test` and `test:coverage`. Default 15s timeout is generous; fail-closed (exit 1) if ports don't open. Strictly better than the fixed `sleep 1` which could race the first network test. |
| Tests pass | ✅ verified | `vendor/bin/phpunit tests/ServerManagerTest.php tests/ControlByteWorkerDosE2ETest.php tests/BinDirectoryTest.php` — 61 tests, 234 assertions, OK (1 skip, 1 no-coverage-driver warning). |
| `php -l bin/wait-for-ports.php` | ✅ verified | No syntax errors. |
| No staged files | ✅ verified | `git status --short` clean; `git diff --cached` empty. |
