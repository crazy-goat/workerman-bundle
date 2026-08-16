# Code decision 1 — PollingMonitorWatcher O(N) sweep (closes #559)

## Approach chosen: iterator held across ticks

The `RecursiveIteratorIterator` is now stored as an instance property
(`$iterators`, keyed by source-dir index) and advanced across ticks using
`valid()` / `current()` / `next()` — **not** `foreach`, because `foreach`
calls `rewind()` at the start of every loop, which would re-traverse the
tree from the root and reintroduce the O(N²/budget) regression.

On the first tick that visits a dir, the iterator is created via
`createRecursiveIterator()` and `rewind()`-ed once.  Subsequent ticks
resume from the stored iterator without rewinding.  When the iterator is
exhausted (`valid()` returns false) the dir's sweep is complete and the
iterator is discarded; the next tick that visits that dir creates a fresh
one.  When a modified file is detected, `resetSweep()` discards all
iterators and resume state so the next tick starts a clean sweep.

### Budget: every entry counts

The old code counted `$filesProcessed++` **after** the resume-skip check,
so skipped (fast-forwarded) entries did not count against
`MAX_FILES_PER_TICK`.  In the new design there is no skip phase — the
iterator simply resumes where it stopped — so the budget check
(`$filesProcessed > self::MAX_FILES_PER_TICK`) bounds **all** entries,
including the first one after a resume.

### getMTime() hoisted

`$file->getMTime()` is called once per file per tick and stored in a
local `$mtime`, used for both the comparison and the `lastMTime` update.

### Tree mutation mid-sweep

The iteration loop is wrapped in `try { ... } catch (\UnexpectedValueException)`.
If a directory is removed between ticks and the iterator tries to descend
into it on the next tick, the exception is caught, the dir's iterator is
discarded, and the next tick starts fresh.  In practice PHP's
`RecursiveIteratorIterator` caches directory listings at `opendir` time
and tends not to throw when files/dirs are removed mid-iteration, but the
catch is a safety net for platforms or PHP versions where the behaviour
differs.  Directories **added** between ticks are not visible in the
current sweep (the root `opendir` listing is already cached) but are
picked up on the next sweep when the iterator is recreated — this is the
same behaviour as the original code, which also rebuilt the iterator
every tick.

### What was rejected

1. **Materialised file list** (collect all paths into an array, hold an
   integer cursor): rejected because it creates a potentially large array
   in worker memory for the lifetime of the sweep.  The iterator-held
   approach has O(1) extra memory (one iterator object per source dir)
   and is closer to the existing design.  The issue explicitly calls out
   the memory concern with the materialised approach.

2. **Making POLLING_INTERVAL / MAX_FILES_PER_TICK configurable**: the
   issue says "consider" and "do not force a config schema change if it
   bloats the change."  Adding configuration would require touching
   `ConfigurationTreeBuilder`, the DI extension, and the constructor
   signature — a significant scope widening for a "consider" item.
   Deferred to a follow-up.

3. **Overriding `reload()` in a test fixture to count calls**: rejected
   because `reload()` is `final protected` on `FileMonitorWatcher`.  The
   mid-sweep detection test instead runs in a subprocess (like the
   existing E2E test) with `SIGUSR1` set to `SIG_IGN`, and asserts
   `lastMTime` was updated (which happens before `reload()` is called).

### Uncertainties

- The `try/catch` for `\UnexpectedValueException` is defensive — in
  testing on PHP 8.5/macOS the iterator did not throw when directories
  were removed mid-iteration.  It may fire on other platforms or when
  the root source dir itself is removed and recreated between ticks.
- The `$resumeDirs` property replaces the old `$resumePaths`.  Tests
  that reflected on `resumePaths` were updated to use `resumeDirs`.
  The semantics changed from "path to resume from" to "dir index with
  an active iterator" — this is an internal detail, not a BC surface.
