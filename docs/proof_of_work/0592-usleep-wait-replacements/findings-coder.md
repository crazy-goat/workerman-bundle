# Findings — coder — #592 usleep/wait replacements

## Obstacles

### 1. SIGCHLD handler dispatch during `Wait::until()` usleep
**File:** `tests/Fixtures/sigchld_test_runner.php:63-76`

`waitForChildren()` and `waitForChildReap()` call `pcntl_signal_dispatch()`
inside the condition callable. This works correctly — the signal is
delivered on each iteration before the condition check. However, the
SIGCHLD signal arrives during `Wait::until()`'s internal `usleep()` and is
only dispatched on the next condition evaluation. This is the same
behaviour as the original hand-rolled loop (dispatch → check → sleep).

### 2. Autoloader not loaded for early fixture runner tests
**File:** `tests/Fixtures/sigchld_test_runner.php:30`, `tests/Fixtures/runner_test_runner.php:30`

The original fixture runners only required the autoloader inside specific
test functions (e.g. `testSchedulerWorkerHandler`). After migrating the
shared helper functions (`waitForChildren`, `waitForChildReap`,
`testSignalKilledChild`, `testTimeoutKillsChild`) to use `Wait::until()`,
the autoloader must be loaded before any test runs. Fixed by adding
`require __DIR__ . '/../../vendor/autoload.php';` at the top of both
files.

### 3. Pre-existing test failures on macOS+grpc host
**File:** `tests/Fixtures/sigchld_test_runner.php` (multiple tests),
`tests/Fixtures/scheduler_worker_runner.php:fork_success`

Several fixture runner tests fail on this macOS host with grpc loaded
(FAQ-007: grpc_shutdown() hangs in forked children, causing SIGKILL
instead of clean exit). Confirmed pre-existing by stashing changes and
running the original code — same failures. CI (Linux, no grpc) is where
these pass. Not caused by the `Wait::until()` migration.

### 4. PHPStan level 8: `file_get_contents` return type
**File:** `tests/ProcessTest.php:273`, `tests/TaskTest.php:68`

`file_get_contents()` returns `string|false`, not `string|null`. The
initial implementation returned `@file_get_contents($path)` directly when
`Wait::until()` returned true, but PHPStan flagged the type mismatch.
Fixed by explicitly checking `$content === false` and returning `null`.

### 5. PHPStan level 8: nullable resource in closures
**File:** `tests/ControlByteWorkerDosE2ETest.php:255,297`

`$this->process` is `resource|null`. Closures passed to `Wait::until()`
that call `proc_get_status($this->process)` must null-check first. Fixed
by assigning to a local and checking for null.

## Bugs / weak spots noticed (including out of scope)

### 1. `$status` variable scoping in `waitForChildReap` closure
**File:** `tests/Fixtures/sigchld_test_runner.php:91`
**Description:** The `static function` closure uses `$status` as a local
variable (output parameter for `pcntl_waitpid`), which is correct — but
the `static` keyword is unnecessary here since there's no `$this` binding
concern in a free function. It works but is slightly misleading.
**Suggested fix:** Remove `static` from closures in free functions where
it adds no value. Low priority — cosmetic.

### 2. `bin/wait-for-ports.php` timeout is integer-only
**File:** `bin/wait-for-ports.php:42`
**Description:** The `--timeout` argument is parsed as `(float)` but
`Wait::until()` takes `int $timeoutSeconds`. A fractional timeout like
`--timeout=1.5` would be truncated to 1.
**Suggested fix:** Either document that timeout is integer seconds, or
cast to `(int) ceil()` to round up. Low priority — 15s default is
sufficient for all known cases.

### 3. Hand-rolled `waitForFile` in `ServerManagerTest` vs `Wait::until`
**File:** `tests/ServerManagerTest.php:1271`
**Description:** The `waitForFile()` helper now delegates to
`Wait::until()` but passes the timeout as an `int` (seconds). The
original used `microtime()` deadlines with sub-second precision. This is
fine for the current use cases (3s timeouts) but the API is coarser.
**Suggested fix:** None needed — the 3s timeout is generous enough.

### 4. Unused `$deadline` variables left in fixture runners
**File:** `tests/Fixtures/sigchld_test_runner.php` (was lines 312, 348)
**Description:** After converting hand-rolled loops to `Wait::until()`,
several `$deadline = microtime(true) + N` assignments became unused.
Rector caught these. Fixed by removing them.
**Suggested fix:** Already fixed.

### 5. `kb-lint` warning: faq.md over 300-line budget
**File:** `docs/helpers/faq.md`
**Description:** Pre-existing warning — faq.md has 307 lines (index
excluded), over the 300-line budget. Not related to this issue.
**Suggested fix:** Promote or drop entries per the knowledge-base decay
rules. Out of scope for #592.

---

## Fix round (review round 1) — obstacles and findings

### 6. R1-F1 failure path is hard to test directly — accepted as untested
**File:** `tests/ServerManagerTest.php:1271` (new `waitForChildReadyOrKill`)
**Description:** Making the orphan-child fix provable would require a
child that fails to touch the readiness marker within 5s (e.g. a child
that sleeps 6s before touching), which adds a 5s+ test to every run for a
path that exists only on pathological hosts. Decided against it: the
try/catch–kill–rethrow helper is 6 lines, trivially reviewable, and the
review itself stated "none automated" is expected for this finding.
**Suggested fix:** None — documented in findings-review.md as
deliberately not regression-tested.

### 7. `(int) ceil()` vs truncation: error message still prints the float
**File:** `bin/wait-for-ports.php:62,68`
**Description:** After the ceil fix, `--timeout=1.5` prints "within 1.5
seconds" but `Wait::until` got 2. Message stays honest ("at least what
the message claims") but a purist could argue for printing the effective
int. Keeping the float is deliberate — the user typed 1.5 and the wait is
>= 1.5.
**Suggested fix:** None.

### 8. Two unrelated `AndIsExecutable` tests exist — only one was flagged
**File:** `tests/CoverageCiGateTest.php:83`
**Description:** `testCoverageScriptExistsAndIsExecutable()` has the same
name/behavior mismatch the review flagged for `BinDirectoryTest`. Review
round 1 did not flag it (possibly because `bin/check-coverage.php` is
meant to be executable, or an oversight). Left untouched — out of the
review's scope; flag for the next review round if desired.
**Suggested fix:** Rename or add an `is_executable()` assertion after
confirming whether check-coverage.php is meant to be chmod +x.

### 9. `startWorker()` still re-reads the PID file after the condition
**File:** `tests/ControlByteWorkerDosE2ETest.php:269`
**Description:** After adding the `<= 0` guard to the Wait::until
condition, the method still does an unconditional
`\file_get_contents($this->tempDir . '/worker.pid')` read on return. A
TOCTOU window (worker re-writes the file between the last poll and the
read) could in theory return a stale value; for PID files this is not a
real failure mode. Per the review, "preserve the later unconditional read
or align it with the validated value" — preserved.
**Suggested fix:** None.
