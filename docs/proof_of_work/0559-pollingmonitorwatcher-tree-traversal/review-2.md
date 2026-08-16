# Review Round 2 — issue #559

**Branch:** `perf/issue-559-pollingmonitorwatcher-restarts-tree-trav`
**Scope:** verify round-1 fixes (commit `099ffd4`) for F1–F5, then hunt
for new issues introduced by the fix.

**KB tags consulted:** `performance`, `long-running`, `polling`,
`watcher`, `inotify`, `timers`, `memory`, `tests`, `state`.
Relevant entries: FAQ-006 (inotify test patterns), FAQ-013 (timer
testing), FAQ-018 (long-running state leakage), FAQ-023 (closure GC
cycles), DEC-003 (worker-level sweeper), DEC-004 (bounded caches),
DEC-013 (fail-open fast-path gates), DEC-014 (bounded static caches +
test affordances). No policy loosening detected.

---

## 1. Per-finding resolution (F1–F5)

| ID | Status | Evidence |
|----|--------|----------|
| F1 | **fixed** (code correct, test does not guard it — see F6) | `PollingMonitorWatcher.php:68-74`: `getMTime()` is wrapped in `try/catch(\RuntimeException)` that does `$iterator->next(); continue;`. `continue` targets the `while ($iterator->valid())` loop (nearest enclosing loop — correct). No double-advance: the catch calls `next()` then `continue` skips the bottom-of-loop `next()` at line 82. `getMTime()` appears only once (grep confirms line 70), always inside the inner try. If the catch's `next()` descends into a deleted dir on Linux, the `UnexpectedValueException` propagates past the inner catch (RuntimeException-only) and is caught by the outer catch at line 84 → iterator discarded. Verified on macOS: `next()` after a deleted-leaf does not throw. |
| F2 | **fixed** | Same inner catch as F1. On macOS where `hasChildren()` returns `false` for a removed directory and it is yielded as a leaf, if the leaf matches the file pattern `getMTime()` throws `RuntimeException` → caught by the inner catch. If the leaf is a directory name that doesn't match the pattern, `getMTime()` is never called (inside the `checkPattern` guard). The outer `UnexpectedValueException` catch is no longer load-bearing on macOS. |
| F3 | **fixed** | `grep -n resumeDirs` across `PollingMonitorWatcher.php`, `PollingMonitorWatcherTest.php`, and `polling_watcher_mid_sweep_runner.php` returns zero matches (exit 1). `$resumeDirs` property declaration, all three set sites, and all unset sites removed. `resetSweep()` now only clears `$this->iterators = []`. Tests use `getIterators()` helper (10 call sites) consistently — no reflection on `resumeDirs` remains. |
| F4 | **fixed** (with caveat — see F6) | `testTreeMutationBetweenTicksDoesNotThrow` restructured: 600 root files + `zzz_subdir` (10 files), removes `zzz_subdir` and adds `newdir/new.php` between ticks, drives up to 8 ticks asserting convergence via `getIterators() === []`. Convergence math: 600 + 1 = 601 entries; worst case (UVE discards iterator → fresh sweep) needs ceil(601/500)=2 ticks + 1 partial = 3 ticks, well within 8. The deterministic deleted-file test exists but does not reliably exercise the RuntimeException path (see F6). |
| F5 | **not fixed (deliberate)** | Out of scope for #559. Confirmed: `foreach ($this->sourceDir as $dirIdx => $dir)` restarts from dir 0 on each tick, re-scanning completed dirs. Same behavior as old code. Default config has 2 small source dirs, overhead negligible. Candidate follow-up issue. |

---

## 2. Overall verdict

**APPROVE_WITH_FINDINGS** — The F1–F4 fixes are correct in the code.
The inner `catch(\RuntimeException)` is properly scoped, control flow is
sound, `$resumeDirs` is fully removed, and the convergence loop is
adequately bounded. One new medium finding (F6): the test that claims to
guard the F1 fix does not deterministically exercise the
`RuntimeException` path on hash-based-readdir filesystems (APFS, ext4
with htree), so it passes vacuously without the inner catch and provides
no regression guard on the developer's platform.

---

## 3. New findings

### F6 — testFileDeletedAtIteratorPositionBetweenTicksDoesNotThrow does not exercise the RuntimeException path on hash-based-readdir filesystems

**File:** `tests/PollingMonitorWatcherTest.php:567` (test method at line 553)
**Severity:** medium

**What is wrong:** The test creates 600 files (`file0.php`–`file599.php`),
runs tick 1 (500 entries), then deletes `file500.php` — assuming the
iterator is parked on `file500.php` (the 501st entry in alphabetical
order). But `RecursiveDirectoryIterator` uses `readdir()`, which on APFS
(and ext4 with htree) returns entries in **hash-based order**, not
alphabetical. On this system, `file500.php` is at readdir index 184 —
already visited in tick 1. The iterator is actually parked on
`file351.php` (index 500), which still exists. In tick 2, `getMTime()` is
never called on the deleted `file500.php`, so the `RuntimeException`
path is never triggered.

**Evidence (runtime verification on this APFS host):**
```
Iterator parked on: file351.php
file500.php was visited in tick 1? YES
Tick 2 threw? NO
Without inner catch, would test fail? NO (deleted file never reached in tick 2)
```

The test passes **with or without** the inner `catch(\RuntimeException)`.
If someone removes the inner catch in a future refactor, this test
provides false confidence — it will still pass.

**Impact:** The F1 fix (the most important finding from round 1, rated
medium) has no reliable regression guard on macOS/APFS or Linux/ext4-
with-htree. The test name and docblock actively mislead reviewers into
believing the RuntimeException path is covered.

**Smallest safe fix direction:** Instead of hardcoding `file500.php`, use
reflection to read the persisted iterator, call `current()` to get the
actual file the iterator is parked on, and delete *that* file:
```php
$iterators = $this->getIterators($watcher);
$iterator = $iterators[0]; // dirIdx 0
$parkedFile = $iterator->current()->getPathname();
\unlink($parkedFile);
\clearstatcache(true, $parkedFile);
```
This makes the test deterministic regardless of readdir ordering.

**Automated check that would have caught it:** No static analysis can
detect this — it requires runtime verification that the test actually
exercises the intended code path. A mutation test (remove the inner
catch, run the test suite, assert at least one test fails) would catch
it. Alternatively, a test that asserts the RuntimeException was actually
thrown and caught (e.g., via a counting iterator fixture that records
getMTime calls) would make the coverage explicit.

---

### F7 — resetSweep() docblock references removed "resume state" (nit)

**File:** `src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:104`
**Severity:** nit

**What is wrong:** The docblock says "Discard all persisted iterators
**and resume state** so the next tick starts a fresh sweep." The
`$resumeDirs` property was removed in the F3 fix, so "resume state" is
stale. The method body is just `$this->iterators = []`.

**Impact:** Cosmetic. No behavioral effect.

**Smallest safe fix direction:** Remove "and resume state" from the
docblock.

**Automated check:** None (docblock accuracy is not linted).

---

## 4. Verification of F1 fix control flow (detailed)

### Does `continue` apply to the `while` loop (correct) or the `foreach` (wrong)?

**Correct — `while`.** The inner `try/catch(\RuntimeException)` is inside
the `if ($this->checkPattern(...))` block, which is inside the `while
($iterator->valid())` loop. In PHP, `continue` targets the nearest
enclosing loop; `try/catch` is not a loop. The `while` loop is the
nearest enclosing loop. The `foreach` is one level further out, past the
outer `try/catch(\UnexpectedValueException)`. Confirmed by code
structure at lines 49–83.

### Double-advance or missed-advance?

**No.** The catch path calls `$iterator->next()` (line 72) then
`continue` (line 74), which skips the bottom-of-loop `$iterator->next()`
at line 82. Exactly one `next()` per iteration on the catch path. On the
normal path (no exception), only the bottom `next()` at line 82 is
called. No double-advance, no missed-advance.

### Could `next()` in the catch throw? Is it caught?

On Linux (ext4, `d_type` cached from readdir): `next()` may call
`hasChildren()` → returns `true` for a directory → `getChildren()` →
`opendir()` on a deleted path → `UnexpectedValueException`. This
propagates past the inner catch (RuntimeException-only) and is caught by
the outer `catch(\UnexpectedValueException)` at line 84 → iterator
discarded via `unset($this->iterators[$dirIdx])`. Consistent state. ✓

On macOS (APFS): `hasChildren()` stats the path → returns `false` for a
missing directory → treated as leaf → no throw. Verified at runtime:
`next()` after processing a leaf in a deleted subtree advances without
throwing. ✓

`next()` does not call `stat()`/`getMTime()` internally, so
`RuntimeException` from `next()` is not a realistic path on either
platform.

### Is `getMTime()` called outside the inner try?

**No.** `grep -n getMTime` confirms a single call site at line 70, inside
the inner `try` block. The comment at line 65 is a reference, not a call.

### Does the test fail without the inner catch?

**No — on this APFS host.** See F6 above. `file500.php` is at readdir
index 184, already visited in tick 1. The deleted file is never reached
in tick 2, so no `RuntimeException` is thrown. The test passes
vacuously.

---

## 5. Edge cases checked

### Recreated file (deleted + recreated between ticks with newer mtime)

The iterator's `current()` returns a `SplFileInfo` pointing at the path
(not the inode). If the file is recreated, `getMTime()` succeeds and
returns the new mtime. If `mtime > lastMTime`, `resetSweep()` + `reload()`
fires. This is correct — a recreated file IS a modification. ✓

### Budget accounting on the catch path

`$filesProcessed++` executes at the top of the `while` loop (line 53),
before the budget check and before the inner try/catch. A skipped-deleted
file still counts against the budget. Intended — every entry counts,
including deleted ones. ✓

### State leakage / unbounded growth

`$iterators` has at most one entry per source dir (bounded by
`count($this->sourceDir)`). Entries are unset on exhaustion, UVE catch,
and resetSweep. No closure cycles, no reference cycles, no unbounded
maps. The only timer is the 3 s polling interval, which survives for the
worker's lifetime (intended). ✓

### PHPStan / type issues from removing `$resumeDirs`

`composer lint` passes (PHPStan level 8, 0 errors). No type issues.
The `@var array<int, true>` annotation on `$resumeDirs` and all
reflection-based test assertions on it are gone. ✓

---

## 6. Commands run

| Command | Result |
|---------|--------|
| `composer lint` | **passed** — PHP CS Fixer 0/248, PHPStan 0 errors, Rector OK, kb-lint OK (1 warning: faq.md 310 lines > 300 budget — pre-existing, not introduced by this change) |
| `php vendor/bin/phpunit tests/PollingMonitorWatcherTest.php tests/PollingMonitorWatcherE2ETest.php --testdox` | **passed** — 15/15 tests, 24 assertions |
| `grep -n resumeDirs` (3 files) | **0 matches** (exit 1) |
| `grep -n getMTime` (source) | **1 match** (line 70, inside inner try) |
| `grep -n getIterators` (test) | **10 matches** (consistent usage) |
| Runtime readdir-order simulation | Confirmed file500.php at index 184, iterator parked on file351.php |
| Runtime next()-throws simulation | next() after deleted-leaf does not throw on APFS |

---

## 7. Candidate knowledge-base entries

### Candidate 1: RecursiveDirectoryIterator readdir order is hash-based on modern filesystems

**Tags:** tests, polling, watcher
**Trigger:** "writing a test that assumes a specific traversal order of
RecursiveDirectoryIterator entries, or that deletes a specific file
expecting it to be at the iterator's current position"

`RecursiveDirectoryIterator` uses `readdir()`, which on APFS and ext4
(with htree/dir_index) returns entries in **hash-based order**, not
alphabetical. A test that creates `file0.php … fileN.php`, processes N
entries, and assumes the iterator is at `fileN.php` is non-deterministic
— on APFS the entry at any given index depends on the hash of the
filename, not its lexicographic position. To deterministically test
behavior at the iterator's current position, read the iterator via
reflection and call `current()` to get the actual parked file, then
mutate that file. Reference: `testFileDeletedAtIteratorPositionBetweenTicksDoesNotThrow`
(issue #559, review round 2 finding F6).

### Candidate 2: none (DEC-014 pattern — bounded cache + test affordance — already covers the iterators array)

---

## 8. Remaining risk areas

**Checked clean:**
- Control flow of inner catch (`continue` targets `while`, not `foreach`)
- Double-advance / missed-advance
- `next()` throwing in the catch path (caught by outer UVE catch on Linux, no throw on macOS)
- `getMTime()` call coverage (single site, inside inner try)
- Budget accounting on catch path
- State leakage / unbounded growth
- BC breaks (no public interface changes; `$resumeDirs` was private)
- PHPStan level 8 compliance

**Not fully verified (platform-specific):**
- The Linux `UnexpectedValueException` path (directory removed mid-descent,
  `hasChildren()` returns true from cached `d_type`, `getChildren()`
  throws) — not testable on macOS. Covered by code inspection and the
  outer catch. The round-1 review acknowledged this as platform-specific.
- Whether `testFileDeletedAtIteratorPositionBetweenTicksDoesNotThrow`
  exercises the RuntimeException path on CI's filesystem (likely tmpfs or
  ext4) — depends on readdir ordering. On tmpfs with alphabetical
  ordering it would work; on ext4 with htree it would not.
