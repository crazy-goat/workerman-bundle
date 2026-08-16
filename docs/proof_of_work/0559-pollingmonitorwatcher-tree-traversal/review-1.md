# Review Round 1 — PollingMonitorWatcher O(N) sweep (#559)

**Reviewer:** review-critical
**Commit:** c02d62a5c1e651410f8428d659f4a995ab7ac0dc
**Branch:** perf/issue-559-pollingmonitorwatcher-restarts-tree-trav
**Date:** 2026-08-16

---

## 1. Earlier findings

`docs/proof_of_work/0559-pollingmonitorwatcher-tree-traversal/findings-review.md`
does not exist yet for round 1 — this is the first review. Proceeding
straight to hunting.

## 2. Knowledge-base check

Loaded the tag indexes of `docs/helpers/faq.md` and `docs/helpers/decisions.md`.
Read the entries matching the diff tags (performance, long-running, polling,
memory, timers, tests, inotify):

- **DEC-003** (timers, long-running) — worker-level sweeper pattern. Not
  directly applicable (PollingMonitorWatcher uses a repeat timer, not the
  connection sweeper), but the "state survives across ticks" principle
  applies. The new `$iterators`/`$resumeDirs` state is worker-lifetime
  state that must be cleaned up on reload — `resetSweep()` does this. ✓
- **DEC-006** (security) — not applicable (no security-relevant code
  touched).
- **DEC-013** (performance, security) — optimization gates on security
  parsers. Not applicable (no security parser touched).
- **DEC-014** (memory, long-running) — bounded static caches. The stored
  iterators are not a static/FIFO cache; they are per-dir iterator objects
  with O(1) extra memory. No cap needed. ✓
- **FAQ-018** (long-running, state) — state survives requests. The
  iterator state is intentionally persistent across ticks (that's the
  whole point). It is reset on reload and on sweep completion. ✓
- **FAQ-023** (closures, gc, memory) — reference cycles in closures. No
  closures are involved in the stored iterators. PHP internal iterator
  objects have proper refcounting. ✓
- **FAQ-013** (timers, tests) — initialize Timer with test event loop.
  Not applicable (the tests invoke `checkFileSystemChanges` directly,
  not via a timer).

No KB entry violations found.

## 3. Overall verdict

**APPROVE_WITH_FINDINGS**

The core change is sound: holding the `RecursiveIteratorIterator` across
ticks and advancing with `valid()`/`current()`/`next()` correctly achieves
O(N) sweeps, the budget now counts every entry, and `getMTime()` is hoisted.
The `rewind()` call on first creation is correct and absent on resume.
The `resetSweep()` on reload is correct. The iterator contract is used
properly.

However, there is one medium-severity finding: the `try/catch` only catches
`\UnexpectedValueException`, but `getMTime()` on a file deleted between
ticks throws `\RuntimeException` — which is not caught and would crash the
worker. This is a new exposure introduced by the change (the old code
rebuilt the iterator every tick, so deleted files were not in the listing).
The tree-mutation test does not exercise this scenario.

## 4. Findings

### F1 | src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:68 | RuntimeException from getMTime() on deleted files is not caught | medium

**Evidence:**

The catch block at line 84 only catches `\UnexpectedValueException`:

```php
} catch (\UnexpectedValueException) {
    unset($this->iterators[$dirIdx], $this->resumeDirs[$dirIdx]);
    continue;
}
```

But `getMTime()` on a deleted file throws `\RuntimeException`, not
`\UnexpectedValueException`. Verified with a PHP script:

```
$iter->rewind();
$iter->next();  // position at file
unlink($iter->current()->getPathname());  // delete between ticks
$iter->current()->getMTime();  // → RuntimeException: stat failed
```

Output:
```
Caught RuntimeException (NOT caught by code): SplFileInfo::getMTime():
  stat failed for .../file1.php
```

**Why it matters:**

The new code holds the iterator across ticks (3-second polling interval).
The file at the iterator's `current()` position was listed by `readdir()`
in a *previous* tick. If a developer deletes that file between ticks
(common in development workflows — renaming, refactoring, cleanup), the
next tick calls `getMTime()` on the now-deleted file, which throws
`RuntimeException`. This exception propagates uncaught and crashes the
worker process.

The old code did not have this exposure: it rebuilt the iterator every
tick, so deleted files were absent from the fresh `readdir()` listing.
The regression is a wider exposure window: microseconds (within-tick
stat-vs-delete race, present in both old and new code) → 3 seconds
(between-tick deletion, new in this code).

**Impact:** Worker crash. Recoverable by the process supervisor, but
disruptive — every file deletion in the watched tree has a ~1/N chance
of being at the iterator's current position when the next tick fires.

**Smallest safe fix direction:** Widen the catch to also handle
`\RuntimeException`, or wrap the `getMTime()` call in its own
try/catch that discards the iterator on stat failure. A
`file_exists()` check before `getMTime()` is an alternative but adds
an extra stat per file (counteracting the optimization goal).

**Automated check that would have caught it:** A test that deletes the
file at the iterator's current position between ticks and asserts
`checkFileSystemChanges` does not throw. PHPStan cannot catch this
(exception type analysis across internal PHP classes is beyond its
scope).

---

### F2 | src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:84 | try/catch(UnexpectedValueException) does not fire on macOS for removed directories | low

**Evidence:**

On macOS (APFS), `RecursiveDirectoryIterator::hasChildren()` stats the
directory path to check if it's a directory. If the directory was removed,
`stat` fails and `hasChildren()` returns `false`. The
`RecursiveIteratorIterator` then treats the removed directory as a leaf
and yields it via `current()`. `getChildren()` (which throws
`UnexpectedValueException`) is never called.

Verified:
```
hasChildren (before removal): true
hasChildren (after removal): false    ← macOS stats, returns false
getChildren(): UnexpectedValueException  ← only if called directly
```

On Linux (ext4), `readdir()` returns `d_type` from the inode, so
`hasChildren()` returns `true` without statting, and `getChildren()`
throws `UnexpectedValueException` (caught). The catch works on Linux
but not on macOS.

The code-decision-1.md acknowledges this: "in testing on PHP 8.5/macOS
the iterator did not throw when directories were removed mid-iteration."

**Why it matters:** The safety net for directory removal is
platform-dependent. On macOS, a removed directory is yielded as a leaf;
if the file pattern matches the directory name (e.g. `*`), `getMTime()`
throws `RuntimeException` (see F1). On Linux, the `UnexpectedValueException`
catch handles it correctly.

**Impact:** Combined with F1, any tree mutation on macOS that results in
a removed file/dir being at the iterator's current position can crash the
worker. On Linux, directory removal is handled but file deletion is not
(same `RuntimeException` issue as F1).

**Fix direction:** Same as F1 — catch `\RuntimeException` in addition to
`\UnexpectedValueException`.

**Automated check:** A platform-specific test that removes an unvisited
directory between ticks and asserts no throw.

---

### F3 | src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:33-34 | resumeDirs is redundant dead state | low

**Evidence:**

`$resumeDirs` is set at the budget boundary (line 58:
`$this->resumeDirs[$dirIdx] = true`) and unset in three places (exception
catch, iterator exhaustion). But it is **never read** in the control flow.
The actual resume decision is based solely on whether `$this->iterators[$dirIdx]`
exists:

```php
$iterator = $this->iterators[$dirIdx] ?? null;
if ($iterator === null) {
    // create new iterator
}
```

`$resumeDirs` is a mirror of `array_keys($this->iterators)` filtered to
dirs that hit the budget. It is only used by tests for state assertions
via reflection.

**Why it matters:** Dead state adds complexity and could confuse future
maintainers into thinking `resumeDirs` controls resume logic. If the two
arrays ever desynchronize (currently impossible given the code, but a
future edit could break the invariant), tests would give false
confidence.

**Fix direction:** Remove `$resumeDirs` and have tests check `$iterators`
instead. Or add a public test affordance method (per DEC-014 pattern)
that exposes the active-iterator set.

**Automated check:** PHPStan with `--dead-code` or a rule detecting
unread private properties.

---

### F4 | tests/PollingMonitorWatcherTest.php | testTreeMutationBetweenTicksDoesNotThrow doesn't exercise the dangerous case | low

**Evidence:**

The test creates `subdir/` with 300 files and `top*.php` with 300 files
(600 total, spanning 2 ticks at 500/tick). On macOS (alphabetical
order), `subdir` (starting with 's') is visited before `top*.php`
(starting with 't'). After tick 1 (500 entries), all 300 subdir files
are processed plus 200 top files. The iterator is positioned at a
top-level file.

Between ticks, `subdir` is removed. But since `subdir` was already fully
visited, the iterator is not positioned inside it. No exception path is
exercised. Verified:

```
After 500 entries, positioned at: top103.php
Is this in subdir? NO
subdir was fully visited: YES
```

The test does not cover:
- (a) Removal of an **unvisited** directory (iterator hasn't descended
  into it yet) — would trigger `UnexpectedValueException` on Linux or
  yield-as-leaf on macOS.
- (b) Deletion of the **file at the iterator's current position** —
  would trigger `RuntimeException` from `getMTime()` (F1).

**Why it matters:** The test name claims "does not throw" for tree
mutations, but it only tests the safe case (already-visited directory
removal). The actual risky scenarios are untested. F1's `RuntimeException`
would not be caught by this test.

**Fix direction:** Restructure the test so the removed directory hasn't
been visited yet — e.g., name it `zzz_subdir` (sorts after `top*.php`),
and ensure tick 1 doesn't reach it (process fewer than 300 top files
before the budget). Add a separate test that deletes the file at the
iterator's current position between ticks.

**Automated check:** The restructured test itself.

---

### F5 | src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:48-51 | Multi-source-dir re-scan: completed dirs re-scanned from scratch | nit

**Evidence:**

When a multi-dir sweep spans multiple ticks, dirs that complete early
(iterator exhausted, unset) get fresh iterators on subsequent ticks
because the `foreach` restarts from dir 0 every tick. Example with 2
dirs of 400 files each (800 total, budget 500):

- Tick 1: dir 0 (400, completes) + dir 1 (100, resumes) = 500
- Tick 2: dir 0 (400, fresh re-scan) + dir 1 (100, resumes) = 500
- Total: 1000 advances for 800 files (vs. ideal 800)

The O(N) claim holds strictly for the single-dir case. For multi-dir,
the overhead is proportional to completed dirs × remaining ticks.
With the typical 2-dir config (src + config), this is small.

**Why it matters:** Minor performance overhead in multi-dir
configurations. Not a correctness issue — the same behavior existed in
the old code. The test `testFullSweepIsLinearNotQuadratic` only tests a
single dir, so this overhead is not verified.

**Fix direction:** Track completed dirs in the current sweep and skip
them in the `foreach` until the sweep resets. Design improvement, not
a bug fix.

**Automated check:** A multi-dir O(N) test.

## 5. Acceptance criteria verification

| # | Criterion | Verified? | Test / evidence |
|---|-----------|-----------|-----------------|
| 1 | O(N) advances per sweep, not O(N²/budget) | **Yes** (single-dir) | `testFullSweepIsLinearNotQuadratic` — asserts 600 ≤ advances ≤ 650 for 600 files. Old code would produce ~1100. Multi-dir case not tested (F5). |
| 2 | No tick > MAX_FILES_PER_TICK including skipped | **Yes** | `testBudgetBoundsAllEntriesIncludingResumedOnes` — asserts per-tick advances ≤ 501 for 1200 files across 3 ticks. Budget counter is at top of while loop, before current(). ✓ |
| 3 | File modified in already-scanned region triggers exactly one reload | **Yes** (implicitly) | `testFileModifiedInAlreadyScannedRegionTriggersReloadOnNextSweep` — subprocess modifies file0.php after sweep completes, asserts lastMTime updated on next sweep. "Exactly one" is implied by code logic (reload → return). No explicit reload-count assertion. |
| 4 | Tree mutation between ticks does not throw | **Partial** | `testTreeMutationBetweenTicksDoesNotThrow` — adds + removes a dir, asserts no throw. But removed dir was already visited (F4). Does not exercise file-at-current-position deletion (F1) or unvisited-dir removal (F2). |
| 5 | getMTime() called at most once per file per tick | **Yes** | `testPollUsesSingleStatPerFile` — asserts statCallCount = 10 for 10 files. One `getMTime()` call site at line 68. ✓ |
| 6 | Existing tests pass | **Yes** | Ran `php vendor/bin/phpunit tests/PollingMonitorWatcherTest.php tests/PollingMonitorWatcherE2ETest.php` — 14 tests, 23 assertions, OK. |
| 7 | CHANGELOG entry under [Unreleased] | **Yes** | Verified in diff — entry under `### Performance` in `[Unreleased]`. |

## 6. Deep review areas checked

### Iterator lifetime across ticks
The stored `RecursiveIteratorIterator` holds `RecursiveDirectoryIterator`
sub-objects with open `opendir` handles. Between ticks (3 s), a few
directory handles remain open (one per directory level in the current
path — not one per directory in the tree). When the watcher is destroyed
or `resetSweep()` is called, the iterator objects become eligible for GC
and PHP's internal destructors close the handles. No FD leak. No
`__destruct` needed on `PollingMonitorWatcher`. **Clean.**

### Manual valid()/current()/next() vs foreach
The `rewind()` call on first creation (line 54) is correct — PHP
iterators are not positioned at the first element after construction.
On resume, `rewind()` is correctly omitted (the iterator continues from
its stored position). `LEAVES_ONLY` mode correctly skips directories.
`beginChildren`/`endChildren` are internal to `RecursiveIteratorIterator`
and not relevant to manual iteration. **Clean.**

### Budget boundary — resume at exact next entry
When `filesProcessed > MAX`, the code returns without calling `next()`.
The iterator stays at the current position. On the next tick, `valid()`
returns true, `current()` returns the same entry, and it is processed.
No entry is skipped or duplicated. The entry that trips the budget is
counted (filesProcessed++) but not processed (no current()/checkPattern/
getMTime) — it is processed on the next tick. **Clean.**

### File at resume point deleted between ticks
If the file at the current position is deleted between ticks, `valid()`
returns true (cached readdir entry), `current()` returns an SplFileInfo
for the deleted path (no stat), `checkPattern()` returns true (filename
matches), and `getMTime()` throws `RuntimeException`. **NOT caught** —
see F1.

### Multi-source-dir handling
`$iterators` is keyed by `dirIdx`. When a dir completes (iterator
exhausted), both `iterators[$dirIdx]` and `resumeDirs[$dirIdx]` are
unset. On reload, `resetSweep()` clears both. Consistent. The only
issue is re-scanning completed dirs (F5), which is a design limitation,
not a bug. **Clean (with F5 caveat).**

### lastMTime update semantics
`lastMTime` is set to the triggering file's mtime before `resetSweep()`
and `reload()`. This preserves "exactly one reload" — the reload happens,
the sweep resets, and the next sweep starts fresh. The clock-skew risk
(file A triggers with mtime 100, file B later in the sweep has mtime 99
and is never checked) is pre-existing — the original code had the same
semantics. Not a regression. **Clean.**

### SIGUSR1 / process safety in subprocess runner
`polling_watcher_mid_sweep_runner.php` sets `pcntl_async_signals(true)`
and `pcntl_signal(SIGUSR1, SIG_IGN)` before calling
`checkFileSystemChanges`. This correctly prevents `Utils::reload()` from
killing the PHPUnit parent. The E2E test's `setUp()` also sets
`SIG_IGN` for `SIGUSR1` as a belt-and-braces measure. **Clean.**

### Test fixture: CountingRecursiveDirectoryIterator
`current()` override increments `$advanceCount` and returns
`CountingSplFileInfo` (via `setInfoClass` in constructor + `assert`).
No `next()` override was added — not needed, since `$advanceCount`
counts `current()` calls (one per user-initiated advance).
`setInfoClass` is preserved in sub-iterators created by `getChildren()`.
**Clean.**

## 7. Candidate knowledge-base entries

### Candidate 1: Stored iterators across ticks must handle deleted-file RuntimeException

**Tags:** long-running, polling, tests
**Trigger:** holding a RecursiveIteratorIterator across timer ticks in a
long-lived worker

When a `RecursiveIteratorIterator` is stored as an instance property and
advanced across timer ticks (as `PollingMonitorWatcher` does for O(N)
sweeps), the file at the iterator's `current()` position may be deleted
between ticks. `SplFileInfo::getMTime()` on a deleted file throws
`\RuntimeException` ("stat failed"), not `\UnexpectedValueException`.
The catch block must cover `\RuntimeException` (or the `getMTime()` call
must be individually guarded). Additionally, on macOS,
`RecursiveDirectoryIterator::hasChildren()` stats the path and returns
`false` for removed directories, so `getChildren()` (which throws
`\UnexpectedValueException`) is never called — the safety net that works
on Linux does not fire on macOS. Test coverage must include: (a) deleting
the file at the iterator's current position between ticks, and (b)
removing an unvisited directory between ticks.

## 8. Commands run

| Command | Result |
|---------|--------|
| `php vendor/bin/phpunit tests/PollingMonitorWatcherTest.php tests/PollingMonitorWatcherE2ETest.php` | **Passed** — 14 tests, 23 assertions, OK (1 warning: no coverage driver) |
| `composer lint` | **Passed** — PHP CS Fixer 0 issues, PHPStan level 8 OK, Rector OK, kb-lint 1 warning (faq.md over line budget, pre-existing) |
