# Findings — Review — #592 usleep/wait replacements

## Round 1

### R1-F1 | tests/ServerManagerTest.php:1089,1203,1236 | Orphaned child process if waitForChildReady throws | medium

All three fork helpers that install signal handlers
(`forkMasterLikeChildWithSignalHandler`, `forkChildIgnoringSignals`,
`forkChildIgnoringSignal`) call `$this->waitForChildReady($readyMarker)`
inside the helper, after `pcntl_fork()` but before `return $pid`.
`waitForChildReady` throws `AssertionFailedError` via `assertTrue` if the
child doesn't touch the marker within 5s. When it throws, the exception
propagates out of the helper before `$pid` is returned to the test method,
so the test method's `try { … } finally { $this->killChildBlocking($pid); }`
is never entered. The child — running `for (;;) { usleep(100_000); }` — is
orphaned. `tearDown` removes the temp dir (files) but does not kill
processes.

In the original code, the `usleep(200_000)` was in the test method, not
the helper, so the helper always returned `$pid` and the test always
entered its try/finally. This is a new failure path introduced by moving
the readiness check into the helper.

**Status:** open
**Severity:** medium
**Smallest fix:** Wrap `waitForChildReady($readyMarker)` in a try/catch
in each fork helper; call `$this->killChildBlocking($pid)` before
re-throwing.
**Automated check:** none — failure-path resource leak under uncommon
conditions.

---

### R1-F2 | bin/wait-for-ports.php:35,63,69 | Fractional --timeout truncated; misleading error message | low

`--timeout` is parsed as `(float)` (line 35) but cast to `(int)` when
passed to `Wait::until()` (line 63). A fractional timeout like
`--timeout=1.5` is silently truncated to 1 second. The error message
(line 69) prints the original float (`$timeoutSeconds` = 1.5), saying
"within 1.5 seconds" when only 1 second was waited.

**Status:** open
**Severity:** low
**Smallest fix:** Use `(int) ceil($timeoutSeconds)` on line 63, or print
the truncated int in the error message.
**Automated check:** a test with `--timeout=1.5` and an unreachable port,
asserting elapsed time.

---

### R1-F3 | tests/ControlByteWorkerDosE2ETest.php:270 | PID > 0 validation dropped from startWorker() readiness condition | low

The original loop checked `$pid > 0 && $this->portIsOpen()` before
returning. The new `Wait::until` condition returns `$this->portIsOpen()`
without validating the PID file content. If the file exists but is empty,
`(int) trim('')` returns 0.

**Status:** open
**Severity:** low
**Smallest fix:** Add `(int) file_get_contents(…) > 0` to the condition,
or validate the returned PID.
**Automated check:** none — requires a specific race condition.

---

### R1-F4 | bin/wait-for-ports.php:32 | Unused $argc variable | nit

`$argc = $_SERVER['argc'] ?? 0;` is assigned but never referenced.
Cargo-culted from `bin/check-coverage.php` which does use `$argc`.

**Status:** open
**Severity:** nit
**Smallest fix:** Remove the line.
**Automated check:** Rector `RemoveUnusedVariableInCodeRule` if enabled.

---

### R1-F5 | tests/BinDirectoryTest.php:82 | Test name promises executability check but only asserts existence | nit

`testWaitForPortsScriptExistsAndIsExecutable()` calls only
`assertFileExists()`. No `is_executable()` assertion is made. The script
is invoked via `php bin/wait-for-ports.php`, so executability is not
required — the test name is misleading.

**Status:** open
**Severity:** nit
**Smallest fix:** Rename to `testWaitForPortsScriptExists()`, or add
`assertTrue(is_executable(…))`.
**Automated check:** none — naming/expectation mismatch only.

---

## Round 1 → status (fix round)

### R1-F1 | Orphaned child if waitForChildReady throws | medium → FIXED

Wrapped the readiness wait in a new private helper
`waitForChildReadyOrKill(string $marker, int $pid)` placed next to
`waitForChildReady` in `tests/ServerManagerTest.php` (line ~1271): it
calls `waitForChildReady($marker)` in a try/catch
(`\PHPUnit\Framework\AssertionFailedError`), and on failure runs
`killChildBlocking($pid)` (SIGKILL + `pcntl_waitpid` reap, per FAQ-007)
before re-throwing the same exception. All three fork helpers
(`forkMasterLikeChildWithSignalHandler` 1094, `forkChildIgnoringSignals`
1208, `forkChildIgnoringSignal` 1241) now call
`$this->waitForChildReadyOrKill($readyMarker, $pid)` — factored once
instead of duplicated 3×, as the review suggested. The test method's
`try/finally { killChildBlocking($pid); }` remains as a second line of
defense; this closes the new failure path where the exception previously
propagated before `$pid` was returned. The assertion message is
unchanged, so the failure mode is still visible in the test output.
`All 61 tests pass (suite intact).`

### R1-F2 | Fractional --timeout truncated; misleading error message | low → FIXED

`bin/wait-for-ports.php` line ~62 now passes `(int) ceil($timeoutSeconds)`
to `Wait::until()` instead of `(int) $timeoutSeconds`. The error message
still prints the untruncated float, so the wait is now *at least* what
the message claims. Verified empirically:
`php bin/wait-for-ports.php 19999 --timeout=1.5` → message "within 1.5
seconds", elapsed ~2.25s (ceil to 2s + `Wait::until`'s documented
backoff overshoot); `--timeout=1` unchanged (~1.24s). Exit code 1 on
timeout unchanged (BinDirectoryTest covers it).

### R1-F3 | PID > 0 validation dropped from startWorker() readiness | low → FIXED

`tests/ControlByteWorkerDosE2ETest.php` `startWorker()` Wait::until
condition now rejects an empty/zero PID file:
`if ((int) \trim((string) \file_get_contents($this->tempDir . '/worker.pid')) <= 0) return false;`
(added after the two `is_file` checks, ~line 249). The final unconditional
read after `Wait::until` returns true is preserved as the review allowed;
the condition guarantees the value read there is > 0 (modulo a TOCTOU
rewrite between the last poll and the read, which cannot produce a `0`
return unless the worker deletes and rewrites its PID file in that
interval — not a real failure mode). If the PID file stays empty, the
wait now times out into the existing "Worker did not become ready within
15s" failure instead of returning PID 0.

### R1-F4 | Unused $argc variable | nit → FIXED

Removed `$argc = $_SERVER['argc'] ?? 0;` from `bin/wait-for-ports.php`
(line 32). The argument loop uses `array_slice($argv, 1)` and checks
`$ports === []`, so the variable was dead.

### R1-F5 | Test name promises executability check | nit → FIXED

Renamed `testWaitForPortsScriptExistsAndIsExecutable()` to
`testWaitForPortsScriptExists()` in `tests/BinDirectoryTest.php` (line
81). No `is_executable()` assertion added — the script is invoked via
`php bin/wait-for-ports.php`, so executability is not required (hard
rule). No other test or script referenced the old name (only
`CoverageCiGateTest` has an unrelated `AndIsExecutable` test that stays).

### Validation summary (fix round)

- `vendor/bin/phpunit tests/ServerManagerTest.php tests/ControlByteWorkerDosE2ETest.php tests/BinDirectoryTest.php` — 61 tests, 234 assertions, OK (1 platform-conditional skip, 1 no-coverage-driver warning).
- `php -l bin/wait-for-ports.php` — clean.
- Manual `--timeout=1.5` / `--timeout=1` runs — see R1-F2.
- `composer lint` — run via pre-push hook (and locally before push).
- Full `composer test` not re-run in this fix round: only test-code +
  bin-script changes, all affected files executed above; daemon ports
  8888/9999 verified free before and after.

### Commitment

All Round 1 findings addressed in a single commit;
`CHANGELOG.md` unchanged (the entry's claims do not materially change —
these are test/tooling correctness fixes, not behavior changes).
