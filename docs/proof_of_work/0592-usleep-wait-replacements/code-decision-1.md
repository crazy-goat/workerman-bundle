# Code decision 1 — #592 usleep/wait replacements

## Approach

The issue targets ~43 fixed-duration `usleep()` calls plus `"sleep 1"` in
the composer test scripts. The bundle ships `Util\Wait::until()` — a
polling helper with exponential backoff — and 14 call sites already use it.
The task is to replace fixed waits with polling on the real condition where
the wait is genuinely waiting FOR A CONDITION, preserving test semantics.

### Categories

**(a) Replaced with `Wait::until()` polling on the real condition:**

- `composer.json` `test`/`test:coverage` scripts: `"sleep 1"` →
  `php bin/wait-for-ports.php 8888 9999`. A new `bin/wait-for-ports.php`
  script polls the test daemon's ports with `Wait::until()` and a 15s
  timeout, exiting non-zero with a clear message if the daemon never
  becomes ready.
- `tests/App/bootstrap.php`: `usleep(500_000)` after `start -d` →
  `Wait::until()` polling port 8888.
- `tests/ServerManagerTest.php`: 10 parent-side `usleep(200_000)` /
  `usleep(100_000)` removed. The fork helpers
  (`forkMasterLikeChildWithSignalHandler`, `forkChildIgnoringSignals`,
  `forkChildIgnoringSignal`) now touch a readiness marker file after
  installing signal handlers, and the parent polls for it via
  `waitForChildReady()` (which uses `Wait::until()`). The hand-rolled
  `waitForFile()` helper now delegates to `Wait::until()`.
- `tests/ProcessInspectorTest.php`: `waitForProcessDeath()`,
  `waitForFile()`, the inline zombie-death poll, the SIGKILL→zombie poll,
  and two "give the kernel a moment" fixed sleeps all replaced with
  `Wait::until()`. The two negative-tests (kill should NOT happen) use
  `Wait::until()` with a 1s timeout as an observation window, then assert
  the process is still alive.
- `tests/WorkermanCommandTest.php`: 3 × `usleep(500_000)` replaced with
  `waitForPortUp()` / `waitForPortDown()` helpers backed by `Wait::until()`.
- `tests/ProcessTest.php`: inline marker-refresh poll, `waitForFile()`,
  `waitForMarkerEntries()` all replaced with `Wait::until()`.
- `tests/ControlByteWorkerDosE2ETest.php`: worker readiness poll,
  stop-worker poll, and two "settle" waits replaced. The settle waits
  (checking PID does NOT change) use `Wait::until()` as an observation
  window.
- `tests/TaskTest.php`: `getTaskStatusFileContent()` poll loop replaced.
- `tests/Phar/SfxDownloaderTest.php`: `waitForTestServer()` poll loop
  replaced.
- `tests/UtilsE2ETest.php`: 2 signal-delivery poll loops inside generated
  subprocess scripts replaced with `Wait::until()`.
- `tests/Fixtures/sigchld_test_runner.php`: `waitForChildren()`,
  `waitForChildReap()`, 4 "ensure child running" delays, and 6 log-poll
  loops replaced.
- `tests/Fixtures/runner_test_runner.php`: 2 "ensure child running" delays
  replaced.
- `tests/Fixtures/scheduler_worker_runner.php`: `waitForChild()` replaced.

**(b) Kept — fixed delay is semantically required:**

- `tests/ServerManagerTest.php` child-side `usleep(100_000)` in `for(;;)`
  loops — keep-alive idiom, not a wait. Commented as intentional.
- `tests/Fixtures/sigchld_test_runner.php` line ~623: `usleep(50_000)`
  before checking log file does NOT contain a crash message — this is a
  pacing delay for file I/O flush, not a condition wait (the test asserts
  absence). Commented as intentional.
- `tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php`:
  `waitForInotifyEvents()` — `usleep(200000)` for inotify kernel event
  delivery. No userspace condition to poll. Commented as intentional.
- `tests/PollingMonitorWatcherTest.php`: `usleep(1000)` to ensure file
  mtime advances past the target. Semantically required. Commented.
- `tests/StatusFileReaderTest.php`: `usleep(100_000)` inside a forked
  child before creating a file — deliberate pacing to test that
  `waitForFile` polls. Commented.

### What was rejected

- **A shared test trait** (`waitForProcess()`, `waitForFile()`,
  `waitForPort()`): the issue suggested this, but each test file already
  has its own private helpers with slightly different signatures and
  semantics (e.g. ProcessTest's `waitForFile` returns content,
  ServerManagerTest's returns void). A trait would force a common
  interface and add indirection for marginal benefit. Rejected in favor
  of in-file helpers that delegate to `Wait::until()`.
- **A lint guard for new `usleep()` in tests**: the issue suggested this,
  but the remaining intentional `usleep()` calls (keep-alive loops,
  inotify pacing, mtime advance, child-side file creation) would need an
  allowlist, making the guard fragile and maintenance-heavy. The comments
  on each intentional sleep serve as documentation for future sweeps.
  This could be a follow-up if the team wants it.
- **Touching `src/Runner::warmUpCache()`**: the issue mentioned the
  production-code `usleep(100_000)` as a related item, but the task
  explicitly says "Do NOT touch non-test code unless a test-only wait
  genuinely requires it." This is out of scope.

### Uncertainties

- On this macOS+grpc host, several `sigchld_test_runner.php` tests fail
  pre-existing (confirmed by stashing changes and running the original
  code). The failures are platform-specific (grpc shutdown handler,
  FAQ-007) and not caused by the `Wait::until()` migration. CI (Linux,
  no grpc) is where these tests actually pass.
- The `waitForChild()` function in `scheduler_worker_runner.php` also
  fails pre-existing on this host (confirmed by stash test). Same
  platform issue.
- Timeouts were chosen to be ≥10× the original fixed delay per the
  issue's acceptance criteria, making the suite more tolerant of slow
  runners, not less.
