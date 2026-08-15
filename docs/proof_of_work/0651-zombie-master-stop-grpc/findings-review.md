# Findings — review round 1, issue #651

Reviewer role: review-critical (ProcessInspector touches signal/process
supervision). Branch `fix/issue-651-workerman-server-stop-can-time-out-and-l`,
commit a7d5775. No prior review round — file created fresh.

## OPEN findings

| # | file:line | What is wrong | Severity | Status |
| --- | --- | --- | --- | --- |
| R1 | `src/ProcessInspector.php:371` | **Fail-open on abnormal `ps` failure.** `readProcessStateViaPs()` treats *any* non-zero exit other than 126/127 as "process gone" (`return ''`), so `isAliveNonLinux()` reports **dead**. That is only correct when `ps` exits 1 because the PID does not exist. If `ps` fails abnormally — sandboxed CLI denying process inspection, fork/resource exhaustion in the shell, a non-conforming `ps` that rejects `-o stat=` — a *live* non-child master is reported dead: `waitForProcessToStop()` returns true, `stop()` reports success, and `cleanupMasterFingerprint()` removes the control plane while the master keeps running. This contradicts the class's documented policy ("fails closed, never open") and the method's own docblock ("When `ps` cannot be executed, the check fails closed") — the fail-closed net only covers exec-disabled and 126/127. Cheap fix: when `$exitCode !== 0`, re-check `posix_kill($pid, 0)`; only return `''` when the PID is genuinely no longer signalable, otherwise return `null` (alive + warning). Note `posix_kill($pid, 0)` already passed at the top of `isProcessAlive()` microseconds earlier, so a still-signalable PID plus a failed `ps` is unambiguous evidence that `ps` — not the process — is the problem. | medium | OPEN |
| R2 | `src/ProcessInspector.php:350-369` | **Fail-closed branches have zero test coverage.** No test exercises: `exec` disabled → `null` → alive+warning; `ps` exit 126/127 → `null` → alive+warning; non-zero exit → `''` → dead. On Linux CI the entire `readProcessStateViaPs()` body is unreachable (gated behind `isLinux()`), so none of the new lines are covered there at all. Testable without platform tricks: the `@exec(...)` call at line 360 is *unqualified* inside namespace `CrazyGoat\WorkermanBundle`, so a test file defining `CrazyGoat\WorkermanBundle\exec()` can simulate exit 1 / 127 / empty output, and the private methods can be driven via reflection (the test file already uses reflection for `waitForProcessToStop`). Such tests would run on Linux CI too and would double as the regression gate for R1. | low | OPEN |
| R3 | `tests/ProcessInspectorTest.php:277` vs `waitForFile()` (line 680) | **Marker-file race in the new zombie test (flakiness window).** Child A publishes B's PID with a bare `file_put_contents($marker, ...)` (no `LOCK_EX`, no write-temp-then-rename) while the parent polls `file_exists($marker)` every 20 ms. Between `O_CREAT` and the write the file exists but is empty; if the parent's poll lands in that window, `(int) @file_get_contents($marker)` is `0` and the test fails with "Child A must publish the grandchild PID" — a flaky failure indistinguishable from a real regression. Microsecond window, but the fix is one flag (`LOCK_EX` does not help the reader side; prefer write to `$marker . '.tmp'` then `rename()`, which is atomic) or make `waitForFile` wait for non-empty content. | nit | OPEN |
| R4 | `src/ProcessInspector.php:340-344` | **Docblock overstates what `''` means.** "an empty string when the process no longer exists" — in fact `''` is returned for *every* non-zero exit other than 126/127, including `ps` errors where the process is alive. Either fix the code (R1) or fix the docblock; as written the contract hides the fail-open path from future maintainers. | nit | OPEN |

## Verified — not findings

- **Coder's out-of-scope claim is real.** `killOrphanedIntermediateFork()`'s
  fingerprint branch (`src/ProcessInspector.php:245-257`) can never kill the
  daemon-mode intermediate: `ServerManager::stop()` (line 50) passes
  `getParentPid($masterPid)` — the intermediate's PID — while the fingerprint
  names the *master* PID (written by `MasterWorker::saveMasterPid()`), and
  `matchesFingerprint()` refuses on any PID mismatch (line 101). By
  construction `$parentPid !== $fingerprint->pid`, so the branch always
  refuses. Pre-existing, out of scope for #651, correctly disclosed in
  `findings-coder.md`; deserves its own issue (ancestry-based verification).
- **`pcntl_waitpid` −1 semantics** are handled correctly: ECHILD ("not my
  child") and "no such process" both fall through to `ps`, which
  disambiguates (state line vs. empty output). The death-between-`posix_kill`-
  and-`waitpid` race resolves correctly on both the direct-child path
  (reaped → `>0` → dead) and the non-child path (`ps` empty → dead).
- **Live probes on this host (macOS, PHP 8.5.9):** `ps -o stat= -p <dead>`
  exits 1 with empty stdout (→ `''` → dead, correct); a real non-child
  zombie reports `Z` (→ dead, correct); a running process reports `Ss  `
  with trailing whitespace (handled by `trim`). Header suppression via
  `stat=` works as intended; a hypothetical non-conforming `ps` that printed
  a `STAT` header would yield a non-`Z` state → alive → fail-*safe*
  direction.
- **No injection surface:** `$pid` is `int`, validated `> 0` at the top of
  `isProcessAlive()`, and `isAliveNonLinux()` is private with a single call
  site below that guard. Int interpolation into the shell command is safe;
  `escapeshellarg` would add nothing.
- **`function_exists('exec')` is the right guard** for PHP ≥ 8.0 (disabled
  functions are removed from the function table; composer requires
  `^8.2`). It is per-function, so an asymmetric `disable_functions`
  (e.g. `shell_exec` allowed, `exec` disabled) is handled correctly. The
  comment "matching the `shell_exec` guard in `Utils.php`" refers to the
  pattern, not the same function — accurate.
- **Test determinism:** `testIsProcessAliveReturnsFalseForNonChildZombie`
  is sound on *both* Darwin (ps path, verified passing) and Linux CI
  (`/proc/<pid>/status` `State: Z` path — a zombie child of the
  never-reaping A reads `Z` there too). It must NOT get
  `@requires OS Darwin`; it is a valid cross-platform regression test.
  `testIsProcessAliveReturnsTrueForNonChildProcess` still passes and its
  assertion remains valid under the new code path.
- **Gates:** `composer lint` green (php-cs-fixer, PHPStan level 8, Rector,
  kb-lint). `vendor/bin/phpunit tests/ProcessInspectorTest.php`: 22 tests,
  37 assertions, 5 skipped (Linux-only on this host), 0 failures.
- **Polling cost:** worst case ~40 `ps` spawns over a 9 s graceful stop
  (`Wait::until` backoff 10→250 ms); each ~5–15 ms on macOS. Negligible.
- **PID-reuse window** (zombie reaped + PID reassigned between the
  `posix_kill($pid, 0)` gate and the `ps` call → `ps` shows a running
  process → "alive") is inherent to any PID-based liveness helper and is
  the same window the master fingerprint's start-time check addresses at
  the higher level. Acceptable for a low-level helper; noted, not a finding.
