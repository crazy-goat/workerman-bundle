# Findings — coder

## Obstacles / surprises

- **`foreach` calls `rewind()`**: The initial instinct was to keep using
  `foreach ($iterator as $file)` with a stored iterator, but `foreach`
  always calls `rewind()` at the start — which re-traverses the tree from
  the root.  Had to switch to manual `valid()` / `current()` / `next()`
  iteration to truly persist position across ticks.

- **`reload()` is `final protected`**: Could not create a test fixture
  that overrides `reload()` to count calls without sending SIGUSR1.
  Used a subprocess runner with `SIG_IGN` instead, mirroring the existing
  E2E test approach.

- **`RecursiveIteratorIterator` caches `opendir` listings**: Directories
  added between ticks are not visible in the current sweep (the root's
  directory listing is already cached from `rewind()` time).  This is
  the same behaviour as the original code and is acceptable — new files
  are picked up on the next sweep.

## Bugs / weak spots noticed

1. **`src/Reboot/FileMonitorWatcher/FileMonitorWatcher.php:154`** —
   `reload()` is `final protected`, which prevents test fixtures from
   intercepting it.  This forces detection tests to run in subprocesses
   with `SIG_IGN`, which is slower and more complex than a simple unit
   test.  Suggested fix: make `reload()` non-final (or extract the
   signal-sending into a separate `final` method) so test subclasses can
   override it.  Low priority — the subprocess approach works.

2. **`src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php`** (old
   code, now fixed) — `POLLING_INTERVAL` and `MAX_FILES_PER_TICK` are
   hardcoded `private const`.  Operators with large source trees cannot
   tune the trade-off between tick length and detection latency without
   patching the source.  The issue says "consider" making them
   configurable; this was deferred to avoid bloating the change with a
   config-schema update.  Suggested fix: add bundle configuration keys
   in a follow-up issue.

3. **`tests/PollingMonitorWatcherTest.php:288-318`** (existing tests,
   now updated) — The old `testMaxFilesPerTickResetsBound` test only
   checked that `resumePaths` was non-empty after one tick with 600
   files.  It did not verify that the *second* tick actually resumed
   from the right position (only that `resumePaths` cleared).  The new
   `testFullSweepIsLinearNotQuadratic` test now explicitly counts
   iterator advances to verify O(N) behaviour.

4. **`tests/Fixtures/polling_watcher_e2e_runner.php:64-67`** — The E2E
   runner only tests the no-change case; it does not test that a file
   modification actually triggers detection (the comment explains why:
   `reload()` sends SIGUSR1).  The new
   `polling_watcher_mid_sweep_runner.php` partially fills this gap by
   testing mid-sweep detection with `SIG_IGN`, but a full
   reload-verification test still requires a multi-process setup.
