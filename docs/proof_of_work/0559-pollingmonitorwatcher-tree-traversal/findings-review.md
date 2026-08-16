# Findings — review (round 1)

<!-- Append-only: one entry per finding. The coder/main session resolves in step 5. -->

## F1 — RuntimeException from getMTime() on deleted files is not caught

- **File:** `src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:68`
- **What is wrong:** The `try/catch` block (line 84) only catches
  `\UnexpectedValueException`, but `SplFileInfo::getMTime()` on a file
  deleted between ticks throws `\RuntimeException` ("stat failed"). The
  new code holds the iterator across ticks (3 s polling interval),
  creating a window where the file at `current()` can be deleted. When
  this happens, the exception propagates uncaught and crashes the worker.
  Verified with a PHP script: `getMTime()` on a deleted file at the
  iterator's current position throws `RuntimeException`, not
  `UnexpectedValueException`.
- **Severity:** medium
- **Status:** fixed (round 2). The `getMTime()` call is now wrapped in its
  own `try/catch (\RuntimeException)` that skips the deleted file
  (`$iterator->next(); continue;`) instead of crashing. The outer
  `catch (\UnexpectedValueException)` still handles directory removal
  mid-descent. New test
  `testFileDeletedAtIteratorPositionBetweenTicksDoesNotThrow` deletes
  the file the iterator is parked on between ticks and asserts the
  sweep completes without throwing.
- **Fix direction:** Widen the catch to `catch (\UnexpectedValueException |
  \RuntimeException)`, or wrap the `getMTime()` call in its own
  try/catch that discards the iterator on stat failure.
- **Automated check:** A test that deletes the file at the iterator's
  current position between ticks and asserts `checkFileSystemChanges`
  does not throw.

## F2 — try/catch(UnexpectedValueException) does not fire on macOS for removed directories

- **File:** `src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:84`
- **What is wrong:** On macOS, `RecursiveDirectoryIterator::hasChildren()`
  stats the directory path and returns `false` for removed directories,
  so `getChildren()` (which throws `UnexpectedValueException`) is never
  called. The removed directory is yielded as a leaf instead. If the
  file pattern matches the directory name, `getMTime()` throws
  `RuntimeException` (see F1). On Linux (ext4), `d_type` is cached from
  `readdir`, so `hasChildren()` returns `true` and `getChildren()` throws
  `UnexpectedValueException` (caught). The safety net is
  platform-dependent. Code-decision-1.md acknowledges this.
- **Severity:** low
- **Status:** fixed (round 2) — same change as F1. With
  `\RuntimeException` now caught around `getMTime()`, a removed
  directory that is yielded as a leaf on macOS (because
  `hasChildren()` returns false on the missing path) and then
  `stat`-ed via `getMTime()` is skipped instead of crashing. The
  platform-dependence of the outer `\UnexpectedValueException` catch
  is no longer load-bearing for correctness.
- **Fix direction:** Same as F1 — catch `\RuntimeException` too.
- **Automated check:** Platform-specific test that removes an unvisited
  directory between ticks.

## F3 — resumeDirs is redundant dead state

- **File:** `src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:33-34`
- **What is wrong:** `$resumeDirs` is set at the budget boundary and
  unset in three places, but is never read in the control flow. The
  actual resume decision is based solely on `$this->iterators[$dirIdx]`
  existence. `$resumeDirs` is only used by tests via reflection.
- **Severity:** low
- **Status:** fixed (round 2). `$resumeDirs` removed entirely;
  tests now inspect `$iterators` via a `getIterators()` helper.
  The iterator array IS the resume state — no redundant mirror.
- **Fix direction:** Remove `$resumeDirs`; have tests check `$iterators`
  or add a public test affordance method.
- **Automated check:** PHPStan dead-code / unread-property rule.

## F4 — Tree mutation test doesn't exercise the dangerous case

- **File:** `tests/PollingMonitorWatcherTest.php` (`testTreeMutationBetweenTicksDoesNotThrow`)
- **What is wrong:** The test creates `subdir/` (300 files) before
  `top*.php` (300 files). On macOS (alphabetical), `subdir` is visited
  first. After tick 1 (500 entries), `subdir` is fully visited and the
  iterator is at a top-level file. Removing `subdir` between ticks
  exercises no exception path. The test does not cover: (a) removal of
  an unvisited directory, (b) deletion of the file at the iterator's
  current position.
- **Severity:** low
- **Status:** fixed (round 2). The test no longer claims to
  exercise the unvisited-directory branch (APFS readdir order is
  hash-based, so which entries are visited in tick 1 is not
  controllable). It now drives ticks until the sweep converges and
  asserts no throw + convergence. The deleted-file-at-current-position
  path (the deterministic RuntimeException case) is covered by the
  new `testFileDeletedAtIteratorPositionBetweenTicksDoesNotThrow`.
  The Linux-only unvisited-directory `UnexpectedValueException` path
  remains platform-specific and is not asserted on macOS; it is
  covered by code inspection and the outer catch.
- **Fix direction:** Restructure so the removed directory hasn't been
  visited yet (e.g. `zzz_subdir`). Add a test that deletes the file at
  the iterator's current position between ticks.
- **Automated check:** The restructured test itself.

## F5 — Multi-source-dir re-scan: completed dirs re-scanned from scratch

- **File:** `src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:48-51`
- **What is wrong:** When a multi-dir sweep spans multiple ticks, dirs
  that complete early get fresh iterators on subsequent ticks (foreach
  restarts from dir 0). This adds overhead proportional to completed
  dirs × remaining ticks. The O(N) claim holds for single-dir; multi-dir
  has extra re-scan cost. Not a correctness issue — same behavior as old
  code. The O(N) test only covers single-dir.
- **Severity:** nit
- **Status:** deliberately not fixed (round 2). Out of scope for
  #559: the issue targets the O(N²/budget) root re-traversal, now
  fixed for the common single-dir case. Re-scanning completed dirs
  on subsequent ticks is a smaller, separate inefficiency with the
  same behavior as the old code and no correctness impact. The
  default config has two small source dirs (src + config), so the
  overhead is negligible in practice. Candidate follow-up issue
  to be filed in step 14.
- **Fix direction:** Track completed dirs and skip them in the foreach
  until the sweep resets. Design improvement, not a bug fix.
- **Automated check:** A multi-dir O(N) test.

## F6 — testFileDeletedAtIteratorPositionBetweenTicksDoesNotThrow does not exercise the RuntimeException path on hash-based-readdir filesystems

- **File:** `tests/PollingMonitorWatcherTest.php:567` (test method at line 553)
- **What is wrong:** The test creates 600 files (`file0.php`–`file599.php`),
  runs tick 1 (500 entries), then deletes `file500.php` — assuming the
  iterator is parked on `file500.php` (the 501st entry in alphabetical
  order). But `RecursiveDirectoryIterator` uses `readdir()`, which on APFS
  and ext4-with-htree returns entries in **hash-based order**, not
  alphabetical. On this system, `file500.php` is at readdir index 184 —
  already visited in tick 1. The iterator is actually parked on
  `file351.php` (index 500), which still exists. In tick 2,
  `getMTime()` is never called on the deleted `file500.php`, so the
  `RuntimeException` path is never triggered. The test passes with or
  without the inner `catch(\RuntimeException)`, providing false confidence.
  Verified at runtime: without the inner catch, tick 2 does not throw
  because the deleted file is never reached.
- **Severity:** medium
- **Status:** fixed (round 3). The test now reads the persisted
  iterator via reflection, calls `current()` to get the actual parked
  file's path, and deletes that file instead of hardcoding `file500.php`.
  It also asserts the parked file matches the watched pattern (so
  `getMTime()` is actually called on it). Verified as a real regression
  guard: with the inner `catch(\RuntimeException)` removed, the test
  fails with `RuntimeException: stat failed for .../file351.php`
  (the actual parked file on this APFS system); with the catch in place
  it passes.
- **Fix direction:** Use reflection to read the persisted iterator, call
  `current()` to get the actual parked file's path, and delete that file
  instead of hardcoding `file500.php`. This makes the test deterministic
  regardless of readdir ordering.
- **Automated check:** A mutation test (remove the inner catch, assert at
  least one test fails) would catch it. No static analysis can detect this.

## F7 — resetSweep() docblock references removed "resume state"

- **File:** `src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:104`
- **What is wrong:** The docblock says "Discard all persisted iterators
  **and resume state** so the next tick starts a fresh sweep." The
  `$resumeDirs` property was removed in the F3 fix, so "resume state" is
  stale.
- **Severity:** nit
- **Status:** fixed (round 3). Docblock now reads "Discard all
  persisted iterators so the next tick starts a fresh sweep from the
  root of every source dir." — the stale "and resume state" phrase is
  gone.
- **Fix direction:** Remove "and resume state" from the docblock.
- **Automated check:** None (docblock accuracy is not linted).

## Round 3 — no new findings, converged

All findings F1–F7 verified on current branch (HEAD = `3807772`):

- F1: fixed — inner `catch(\RuntimeException)` at `PollingMonitorWatcher.php:70` confirmed load-bearing via mutation test (replacing with `\LogicException` makes `testFileDeletedAtIteratorPositionBetweenTicksDoesNotThrow` fail with `RuntimeException: stat failed for .../file351.php`).
- F2: fixed — same inner catch covers macOS leaf-directory case.
- F3: fixed — no `$resumeDirs` property exists; `iterators` array is sole resume state.
- F4: fixed — tree-mutation test drives to convergence; deleted-file path covered by dedicated test.
- F5: deliberately out of scope (documented).
- F6: fixed — test reads `$iterators[0]->current()` via reflection, deletes the actual parked file (not a hardcoded name), asserts `.php` suffix so `getMTime()` is exercised. Mutation test confirms real regression guard.
- F7: fixed — docblock no longer references "resume state".

No new issues introduced by the round-2 fix. `composer lint` passes. All 15 targeted tests pass. Working tree clean after mutation test restoration.
