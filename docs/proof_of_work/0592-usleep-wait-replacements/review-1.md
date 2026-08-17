# Review — Round 1 — #592 usleep/wait replacements

**Branch:** `test/issue-592-43-fixed-duration-usleep-waits-plus-slee`
**Diff:** `master...HEAD` — 21 files, ~678 insertions / ~251 deletions
**Scope:** All test/deps/tooling, no `src/` changes.

## 1. Earlier findings

`findings-review.md` does not exist yet — this is round 1. No earlier
review findings to revisit.

## 2. Overall verdict

**Approve with medium findings.** The migration from fixed-duration
`usleep()`/`sleep()` to `Wait::until()` polling is well-executed: every
replaced call site polls the real async condition (port up/down, process
alive/dead, file exists, log content, signal handler installed), timeouts
are generous enough for slow CI, and `Wait::until`'s return value is
asserted where it matters (or correctly ignored for observation windows).
The intentionally-kept `usleep()`/`sleep()` calls are all semantically
required (keep-alive loops, mtime pacing, inotify event delivery, TTL
testing, child-side process simulation) and carry explanatory comments.
The generated PHP scripts in `UtilsE2ETest.php` require the autoloader
before `Wait` is used. `bin/wait-for-ports.php` fails closed (exit 1 on
timeout, exit 2 on bad usage). The 80% coverage floor is not endangered
(`phpunit.xml` `<source>` includes only `src/`, so `bin/` is excluded from
coverage measurement).

One medium-severity resource-leak hazard in the `ServerManagerTest` fork
helpers should be fixed before merge; the rest are low/nit.

## 3. New findings

### R1-F1 | tests/ServerManagerTest.php:1089,1203,1236 | Orphaned child process if `waitForChildReady` throws | medium

**Evidence:** All three fork helpers that install signal handlers
(`forkMasterLikeChildWithSignalHandler`, `forkChildIgnoringSignals`,
`forkChildIgnoringSignal`) now call `$this->waitForChildReady($readyMarker)`
**inside the helper**, after `pcntl_fork()` but before `return $pid`:

```php
$this->writeMatchingFingerprint($pid);
$this->waitForChildReady($readyMarker);   // ← can throw
return $pid;
```

`waitForChildReady` calls `$this->assertTrue($ready, ...)` which throws
`AssertionFailedError` if the child didn't touch the marker within 5s.
When this happens, the exception propagates out of the helper **before**
`$pid` is returned to the test method. The test method's `try { … }
finally { $this->killChildBlocking($pid); }` block is never entered, so
the child — which is running `for (;;) { usleep(100_000); }` — is never
killed. `tearDown` calls `$this->removeDir($this->tmpDir)` (file cleanup
only, not process cleanup).

In the **original** code, the `usleep(200_000)` was in the **test method**,
not the helper. The helper always returned `$pid`, so the test method
always entered its try/finally and killed the child on any failure.

**Impact:** On a pathological host (filesystem full so `@touch` fails, or
the child hangs before touching the marker), the child is orphaned and
runs forever. On CI this can cause resource exhaustion or interference
with subsequent test runs. Per FAQ-007, forked children on macOS/grpc
hosts can behave unexpectedly, making this more than theoretical.

**Smallest safe fix:** Wrap the `waitForChildReady` call in a try/catch
in each fork helper; kill the child before re-throwing:

```php
try {
    $this->waitForChildReady($readyMarker);
} catch (\PHPUnit\Framework\AssertionFailedError $e) {
    $this->killChildBlocking($pid);
    throw $e;
}
```

**Check that would catch it:** None automated — this is a
failure-path resource leak that only manifests under uncommon conditions.
A test that simulates a child failing to touch the marker could catch it,
but the more practical guard is the try/catch above.

---

### R1-F2 | bin/wait-for-ports.php:35,63,69 | Fractional `--timeout` silently truncated; error message reports the un-truncated value | low

**Evidence:** `--timeout` is parsed as `(float)` on line 35, but cast to
`(int)` on line 63 when passed to `Wait::until()`:

```php
$timeoutSeconds = (float) $m[1];          // line 35 — e.g. 1.5
…
$ready = …Wait::until($check, (int) $timeoutSeconds);  // line 63 — truncated to 1
```

The error message on line 69 prints the original float:

```php
sprintf("…within %s seconds.\n", …, $timeoutSeconds)   // says "1.5 seconds"
```

So `--timeout=1.5` waits 1 second but the error message claims 1.5.

**Impact:** Misleading diagnostics when a fractional timeout is used. The
default (15s, integer) is not affected. The `BinDirectoryTest` timeout test
uses `--timeout=1` (integer), so this is not caught by existing tests.

**Smallest safe fix:** Use `(int) ceil($timeoutSeconds)` on line 63 to
round up, or print `(int) $timeoutSeconds` in the error message.

**Check that would catch it:** A test passing `--timeout=1.5` with an
unreachable port and asserting elapsed time ≈ 1.5s (not 1.0s).

---

### R1-F3 | tests/ControlByteWorkerDosE2ETest.php:270 | `$pid > 0` validation dropped from `startWorker()` readiness condition | low

**Evidence:** The original loop checked `$pid > 0` before returning:

```php
$pid = (int) \trim((string) \file_get_contents($pidFile));
if ($pid > 0 && $this->portIsOpen()) {
    return $pid;
}
```

The new `Wait::until` condition returns `$this->portIsOpen()` without
reading or validating the PID file content:

```php
return $this->portIsOpen();   // no PID check
```

After `Wait::until` returns true, the method reads the PID file
unconditionally: `return (int) \trim((string) \file_get_contents(…));`.
If the file exists but is empty, this returns 0.

**Impact:** In practice the worker writes its PID before binding the port,
so the race is unlikely. But if it does occur, the caller gets PID 0,
which would cause a confusing downstream failure rather than a clear
"worker did not become ready" message.

**Smallest safe fix:** Add `(int) file_get_contents($this->tempDir . '/worker.pid') > 0`
to the condition, or validate the returned PID before returning.

**Check that would catch it:** None — requires a specific race condition
to trigger.

---

### R1-F4 | bin/wait-for-ports.php:32 | Unused `$argc` variable | nit

**Evidence:** `$argc = $_SERVER['argc'] ?? 0;` is assigned but never
referenced. The argument loop uses `array_slice($argv, 1)` and checks
`$ports === []` for emptiness.

**Impact:** Dead code. `bin/check-coverage.php` uses the same pattern but
actually checks `$argc`. Here it's cargo-culted.

**Smallest safe fix:** Remove the line.

**Check that would catch it:** Rector's `RemoveUnusedVariableInCodeRule`
if enabled; otherwise manual review.

---

### R1-F5 | tests/BinDirectoryTest.php:82 | Test name promises executability check but only asserts file existence | nit

**Evidence:**

```php
public function testWaitForPortsScriptExistsAndIsExecutable(): void
{
    $this->assertFileExists($this->projectDir . '/bin/wait-for-ports.php');
}
```

The name says "AndIsExecutable" but no `is_executable()` assertion is
made. The script is invoked via `php bin/wait-for-ports.php`, so
executability is not required — the test name is misleading.

**Impact:** Cosmetic. A reader might assume executability is verified.

**Smallest safe fix:** Rename to `testWaitForPortsScriptExists()`, or add
`$this->assertTrue(is_executable(…))` if executability is actually desired.

**Check that would catch it:** None — naming/expectation mismatch only.

## 4. Candidate knowledge-base entries

**Candidate 1: Fork-helper readiness markers must clean up the child on failure**
- **Tags:** tests, process
- **Trigger:** "adding a readiness wait inside a fork helper"
- **Paragraph:** When a fork helper waits for a child-side readiness marker
  (file touch) **inside the helper** (before returning the PID), a failure
  of that wait orphans the child: the test method's `try/finally` that
  calls `killChildBlocking($pid)` is never entered because the exception
  propagates from the helper before `$pid` is returned. Wrap the readiness
  wait in a try/catch inside the helper and kill the child before
  re-throwing. This pattern was introduced in #592 when `usleep(200_000)`
  was replaced with `Wait::until()` polling on a marker file.

## 5. Remaining risk areas checked clean

| Area | Verdict | Evidence |
|------|---------|----------|
| `Wait::until()` conditions poll real async state | ✅ clean | Every condition callable checks the actual condition: `fsockopen` for ports, `posix_kill($pid, 0)` for process alive, `file_exists` for files, `file_get_contents` + `str_contains` for log content, `pcntl_signal_dispatch()` + flag check for signals |
| `pcntl_signal_dispatch()` in `sigchld_test_runner.php` | ✅ clean | Called inside every condition callable in `waitForChildren`, `waitForChildReap`, and the log-poll loops. Same dispatch-then-check semantics as the original hand-rolled loops. |
| `UtilsE2ETest.php` generated scripts | ✅ clean | Both heredocs have `require <autoload_path>;` as the first executable line, before `Wait::until()` is used. `var_export($this->autoloadPath, true)` produces a valid string literal. |
| `bin/wait-for-ports.php` fail-closed | ✅ clean | Exit 1 on timeout, exit 2 on missing/unknown args. `BinDirectoryTest` covers both. |
| `composer.json` script quoting | ✅ clean | `php bin/wait-for-ports.php 8888 9999` — simple command, no special characters. Both `test` and `test:coverage` updated identically. |
| Coverage floor | ✅ clean | `phpunit.xml` `<source><include>` lists only `src/`; `bin/` is excluded from coverage. `coverage:check` unchanged at 80.0. |
| Remaining `usleep`/`sleep` in tests | ✅ clean (intentional) | All remaining calls are child-side keep-alive loops (`for(;;) { usleep(100_000); }`), mtime pacing (1ms), inotify kernel event delivery (200ms), TTL testing (1s), or child-side process simulation. Each has an explanatory comment. |
| Marker file cleanup | ✅ clean (with R1-F1 caveat) | Markers are in `sys_get_temp_dir()/workerman_server_test_<uniqid>/`, unlinked by `waitForChildReady` via `@unlink`, and the entire dir is removed by `tearDown → removeDir`. No orphaned files. |
| `forkMasterLikeChild` (no handler) | ✅ clean | No readiness check needed — child just `for(;;) { sleep(1); }`. The removed `usleep(100_000)` was in the test method, not the helper. `posix_kill($pid, 0)` returns true immediately after fork, so the removed sleep is not needed. |
| CHANGELOG | ✅ clean | Entry accurately describes the change: 43 waits replaced, `Wait::until()` polling, `bin/wait-for-ports.php`, child-side loops kept. |
| `bin/` within linter scope | ✅ noted | `phpstan.neon.dist` and `.php-cs-fixer.dist.php` both include `bin/` — contrary to the review prompt's general statement. `php -l bin/wait-for-ports.php` passes. The file follows the same `$argv = $_SERVER['argv'] ?? []` pattern as `bin/check-coverage.php`. |
| Observation-window waits (negative tests) | ✅ clean | `ProcessInspectorTest` (2 × 1s) and `ControlByteWorkerDosE2ETest` (2s + 3s) use `Wait::until` with intentionally-unsatisfiable conditions as observation windows. Return value correctly ignored. Asserts the expected state after the window. |
| `scheduler_worker_runner.php` `waitForChild` | ✅ clean | Uses by-ref captures (`&$pid`, `&$status`) in the closure to communicate `pcntl_wait` results out. Returns `-2` on timeout, `-1` on error, `$status` on success — same as original. |
| `ProcessTest.php` double-read pattern | ✅ clean | `waitForFile` and `waitForMarkerEntries` call `file_get_contents` in the condition, then re-read after `Wait::until` returns true. TOCTOU-safe: handles `false` return with null fallback. Slightly redundant I/O but correct. |
