# Findings — review round 1, issue #651

Reviewer role: review-critical (ProcessInspector touches signal/process
supervision). Branch `fix/issue-651-workerman-server-stop-can-time-out-and-l`,
commit a7d5775. No prior review round — file created fresh.

## OPEN findings

| # | file:line | What is wrong | Severity | Status |
| --- | --- | --- | --- | --- |
| R1 | `src/ProcessInspector.php:371` | **Fail-open on abnormal `ps` failure.** `readProcessStateViaPs()` treats *any* non-zero exit other than 126/127 as "process gone" (`return ''`), so `isAliveNonLinux()` reports **dead**. That is only correct when `ps` exits 1 because the PID does not exist. If `ps` fails abnormally — sandboxed CLI denying process inspection, fork/resource exhaustion in the shell, a non-conforming `ps` that rejects `-o stat=` — a *live* non-child master is reported dead: `waitForProcessToStop()` returns true, `stop()` reports success, and `cleanupMasterFingerprint()` removes the control plane while the master keeps running. This contradicts the class's documented policy ("fails closed, never open") and the method's own docblock ("When `ps` cannot be executed, the check fails closed") — the fail-closed net only covers exec-disabled and 126/127. Cheap fix: when `$exitCode !== 0`, re-check `posix_kill($pid, 0)`; only return `''` when the PID is genuinely no longer signalable, otherwise return `null` (alive + warning). Note `posix_kill($pid, 0)` already passed at the top of `isProcessAlive()` microseconds earlier, so a still-signalable PID plus a failed `ps` is unambiguous evidence that `ps` — not the process — is the problem. | medium | FIXED — `readProcessStateViaPs()` now re-checks `posix_kill($pid, 0)` on non-zero exit: still-signalable → `null` (alive + warning), unsignalable → `''`. Docblock updated (R4). |
| R2 | `src/ProcessInspector.php:350-369` | **Fail-closed branches have zero test coverage.** No test exercises: `exec` disabled → `null` → alive+warning; `ps` exit 126/127 → `null` → alive+warning; non-zero exit → `''` → dead. On Linux CI the entire `readProcessStateViaPs()` body is unreachable (gated behind `isLinux()`), so none of the new lines are covered there at all. Testable without platform tricks: the `@exec(...)` call at line 360 is *unqualified* inside namespace `CrazyGoat\WorkermanBundle`, so a test file defining `CrazyGoat\WorkermanBundle\exec()` can simulate exit 1 / 127 / empty output, and the private methods can be driven via reflection (the test file already uses reflection for `waitForProcessToStop`). Such tests would run on Linux CI too and would double as the regression gate for R1. | low | FIXED — added `testReadProcessStateViaPsReturnsEmptyForNonExistentPid` (non-zero exit + unsignalable → `''`), `testReadProcessStateViaPsReturnsStateForRunningPid` (exit 0 + running → non-Z state), `testIsAliveNonLinuxReturnsFalseForNonExistentPid`, `testIsAliveNonLinuxReturnsTrueForRunningPid`. All `@requires OS Darwin` (the `ps` path is non-Linux only). exec-disabled/126/127 guards are defensive PHP-level checks not deterministically testable without process isolation; covered by the R1 posix_kill recheck logic. |
| R3 | `tests/ProcessInspectorTest.php:277` vs `waitForFile()` (line 680) | **Marker-file race in the new zombie test (flakiness window).** Child A publishes B's PID with a bare `file_put_contents($marker, ...)` (no `LOCK_EX`, no write-temp-then-rename) while the parent polls `file_exists($marker)` every 20 ms. Between `O_CREAT` and the write the file exists but is empty; if the parent's poll lands in that window, `(int) @file_get_contents($marker)` is `0` and the test fails with "Child A must publish the grandchild PID" — a flaky failure indistinguishable from a real regression. Microsecond window, but the fix is one flag (`LOCK_EX` does not help the reader side; prefer write to `$marker . '.tmp'` then `rename()`, which is atomic) or make `waitForFile` wait for non-empty content. | nit | FIXED — child A now writes to a temp file and `rename()`s it atomically; the parent never observes an empty marker. |
| R4 | `src/ProcessInspector.php:340-344` | **Docblock overstates what `''` means.** "an empty string when the process no longer exists" — in fact `''` is returned for *every* non-zero exit other than 126/127, including `ps` errors where the process is alive. Either fix the code (R1) or fix the docblock; as written the contract hides the fail-open path from future maintainers. | nit | FIXED — docblock now distinguishes "empty string when the process no longer exists (ps exit 0 with no output, or non-zero exit on an unsignalable PID)" from "null when ps could not be executed or exited abnormally on a still-signalable PID". |

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

## Round 2

Reviewer role: review-critical (round 2), fix commit 4a1332f on top of
a7d5775. Full details in `review-2.md`. Gates: phpunit 26 tests / 42
assertions / 5 skipped (Linux-only) / 0 failures on macOS PHP 8.5.9;
`composer lint` green.

### Round-1 finding status

- **R1 — FIXED.** `src/ProcessInspector.php:380-391` re-checks
  `posix_kill($pid, 0)` on non-zero `ps` exit: still-signalable → `null`
  (alive + warning, line 387), unsignalable → `''` (dead, line 390). The
  discriminator is correct and there is no harmful TOCTOU against the
  entry guard (line 36): death between the two checks yields the correct
  "dead"; EPERM cannot newly appear (UID cannot change); PID reuse
  resolves fail-safe. Non-child zombie + broken `ps` → treated alive is
  the documented fail-closed policy in a degraded environment.
- **R2 — FIXED.** Four `@requires OS Darwin` tests added
  (`tests/ProcessInspectorTest.php:893, 912, 952, 969`), all executed and
  passing on this host. They exercise exit-0-with-output,
  non-zero+unsignalable → `''`, and the end-to-end `isAliveNonLinux`
  dead/running paths. Residual gap tracked as N3.
- **R3 — FIXED.** Marker write is now write-to-`$marker.<rand>.tmp` +
  `rename()` (`tests/ProcessInspectorTest.php:279-281`); same filesystem,
  atomic, the parent can never observe an empty marker.
- **R4 — FIXED.** Docblock (`src/ProcessInspector.php:340-346`) now
  distinguishes `''` (exit 0 no output, or non-zero exit on an
  unsignalable PID) from `null` (ps not executable / abnormal exit on a
  still-signalable PID → fail closed) and matches the code branch-for-branch.

### NEW findings (round 2)

| # | file:line | What is wrong | Severity | Status |
| --- | --- | --- | --- | --- |
| N1 | `src/ProcessInspector.php:373-375` | Inline comment in the new R1 branch contradicts the code: "An empty result on a still-signalable PID means the process is gone" — a still-signalable PID with a failed `ps` is treated as *alive* (`return null`, line 387); "gone" is returned only for an *unsignalable* PID (line 390). Comment-only, but it is the same docblock/code mismatch class as R4. | nit | FIXED — comment rewritten to match the code: signalable + failed ps → alive (fail closed); unsignalable → gone. |
| N2 | `tests/ProcessInspectorTest.php:961-964` | `testIsAliveNonLinuxReturnsTrueForRunningPid` docblock claims to cover the "ps returns a non-Z state" happy path, but the forked child is a direct child, so `pcntl_waitpid` returns 0 and the method returns at the direct-child branch without invoking `ps`. Assertion is valid and deterministic; docblock only. The non-child ps happy path is covered by `testIsProcessAliveReturnsTrueForNonChildProcess` (line 222), so coverage is complete. | nit | FIXED — docblock corrected: test covers the direct-child (pcntl_waitpid returns 0) path; non-child ps coverage is provided by `testIsProcessAliveReturnsTrueForNonChildProcess`. |
| N3 | `src/ProcessInspector.php:381-388` | The core R1 fail-closed branch (non-zero `ps` exit on a *signalable* PID → `null`) has no test. Reachable deterministically via the round-1 lever — `@exec` at line 362 is unqualified in namespace `CrazyGoat\WorkermanBundle`, so a test-side `CrazyGoat\WorkermanBundle\exec()` override could simulate `ps` failing on a live PID, even on Linux CI — but that override is global to the namespace for the whole PHPUnit process and risks polluting other tests. Branch fails closed and both its sub-conditions are individually exercised; acceptable residual gap. | low | OPEN (acceptable) — deliberately not tested: the namespaced `exec` stub would pollute the PHPUnit process namespace-wide; branch fails closed and both sub-conditions (signalable via posix_kill, ps non-zero exit) are individually exercised. |

**Round-2 verdict: clean enough to merge.** N1/N2 are one-line comment
corrections; N3 is a low, acceptable coverage gap. No round 3 needed.
