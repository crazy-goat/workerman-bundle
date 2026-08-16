# Review — Round 3 (convergence check)

**Branch:** `perf/issue-559-pollingmonitorwatcher-restarts-tree-trav`
**Issue:** #559
**Date:** 2026-09-18
**Reviewer:** review-critical agent (round 3)
**Base commit for diff:** `099ffd4` (end of round-1 fixes)
**Head commit:** `3807772` (round-2 fix: deterministic test + stale docblock)

---

## 1. Earlier findings — resolution table

| ID | Status | Evidence |
|----|--------|----------|
| F1 | **fixed** | `src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:68-72` — `getMTime()` is wrapped in `try/catch (\RuntimeException)` that calls `$iterator->next(); continue;`. The outer `catch (\UnexpectedValueException)` at line 84 still handles directory removal. Mutation test confirms the guard is load-bearing (see §3). |
| F2 | **fixed** | Same inner `catch (\RuntimeException)` at line 70 covers the macOS case where a removed directory is yielded as a leaf and `getMTime()` throws `RuntimeException` instead of the outer `UnexpectedValueException` path. The platform-dependence of the outer catch is no longer load-bearing. |
| F3 | **fixed** | No `$resumeDirs` property exists anywhere in `PollingMonitorWatcher.php` (grep confirms zero matches in `src/`). The `iterators` array (line 22) is the sole resume state. Tests use `getIterators()` helper. |
| F4 | **fixed** | `testTreeMutationBetweenTicksDoesNotThrow` (line 469) no longer claims to exercise the unvisited-directory branch. It creates `zzz_subdir`, mutates the tree, and drives ticks to convergence. The deterministic deleted-file-at-current-position path is covered by the separate `testFileDeletedAtIteratorPositionBetweenTicksDoesNotThrow` (line 577). |
| F5 | **deliberately out of scope** | `foreach ($this->sourceDir as $dirIdx => $dir)` still restarts from index 0 on every tick (line 49). Documented as out of scope for #559 — the issue targets the O(N²/budget) root re-traversal, now fixed for the single-dir case. No correctness impact. |
| F6 | **fixed** | `tests/PollingMonitorWatcherTest.php:589-602` — test now reads `$iterators = $this->getIterators($watcher)`, gets `$iterators[0]`, asserts `InstanceOf(\Iterator::class)`, asserts `valid()`, calls `current()` to get the actual parked `SplFileInfo`, asserts `InstanceOf(\SplFileInfo::class)`, gets `getPathname()`, asserts `FileExists`, asserts `StringEndsWith('.php')`, then deletes that path via `unlink($parkedPath)`. No hardcoded filename. Mutation test confirms it is a real regression guard (see §3). |
| F7 | **fixed** | `src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:103-104` — docblock now reads "Discard all persisted iterators so the next tick starts a fresh sweep from the root of every source dir." The stale "and resume state" phrase is gone. |

---

## 2. Overall verdict

**APPROVE.** All seven findings (F1–F7) are resolved: F1–F4 fixed, F5 deliberately out of scope (documented), F6–F7 fixed in commit `3807772`. The round-2 diff is scoped to exactly the F6/F7 fix — 2 files, no unrelated changes. Lint (PHP CS Fixer + PHPStan level 8 + Rector + kb-lint) passes clean. All 15 targeted tests pass. The mutation test for F6 confirms the test is a real regression guard, not a vacuous pass. No new issues found.

---

## 3. Mutation test for F6 (regression guard verification)

**Performed:** Yes.

**Method:** Replaced `catch (\RuntimeException)` with `catch (\LogicException)` in `src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:70`. `LogicException` is a sibling of `RuntimeException` (both extend `\Exception`), so it does not catch `RuntimeException` — this disables the inner guard while keeping the try/catch structure syntactically valid. Ran the single test, then restored the file via `cp` from backup and verified `git diff --stat` shows no changes.

**Result:** The test **FAILS** with:
```
RuntimeException: SplFileInfo::getMTime(): stat failed for
  /var/folders/.../workerman_polling_8045538d/file351.php
```
The parked file is `file351.php` (readdir index 500 on this APFS system), **not** `file500.php` — confirming the round-2 finding that hardcoding `file500.php` was wrong. With the original `catch (\RuntimeException)` restored, the test passes. This proves the test exercises the `RuntimeException` path and is a genuine regression guard for the F1 fix.

**Restoration verified:** `git status --short` is empty; `grep 'catch' src/.../PollingMonitorWatcher.php` shows `catch (\RuntimeException)` and `catch (\UnexpectedValueException)` — both correct.

---

## 4. New findings

**No new findings — converged.**

The round-2 fix was examined for:
- **`$iterators[0]` key correctness:** The test uses `[$this->tempDir]` (single source dir), so `dirIdx=0` is correct. `assertNotEmpty($iterators)` guards the case where the iterator might not be persisted.
- **`$iterators` emptiness:** With 600 files and `MAX_FILES_PER_TICK=500`, tick 1 stops at the budget boundary with a persisted iterator. The `assertNotEmpty` guard would fail (not silently pass) if this invariant broke.
- **Type safety of the reflection/assertInstanceOf chain:** `getIterators()` returns `array<int, mixed>`. `$iterators[0] ?? null` → `mixed|null`. `assertInstanceOf(\Iterator::class, $iterator)` narrows to `\Iterator`. `$iterator->current()` returns `mixed`. `assertInstanceOf(\SplFileInfo::class, $parkedFile)` narrows to `\SplFileInfo`. `$parkedFile->getPathname()` returns `string`. PHPStan level 8 passes — confirmed type-safe.
- **Leftover hardcoded `file500.php` references:** `grep -rn 'file500' tests/ src/` returns no matches. Clean.
- **Fixture comment `no resume state`:** `tests/Fixtures/polling_watcher_mid_sweep_runner.php:80` — this is a descriptive comment for the `iterators` emptiness assertion, not a reference to the removed `$resumeDirs` property. Not a finding.
- **Diff scope:** `git diff 099ffd4..HEAD -- src/ tests/` shows only `PollingMonitorWatcher.php` (docblock) and `PollingMonitorWatcherTest.php` (test method). No unrelated changes.

---

## 5. Commands run

| Command | Result |
|---------|--------|
| `composer lint` | **passed** (PHP CS Fixer: 0 issues; PHPStan level 8: OK; Rector: OK; kb-lint: 1 warning about FAQ line budget, unrelated to this change) |
| `php vendor/bin/phpunit tests/PollingMonitorWatcherTest.php tests/PollingMonitorWatcherE2ETest.php --testdox` | **passed** (15/15 tests, 30 assertions; exit code 1 is due to "No code coverage driver available" warning, not a test failure) |
| Mutation test (replace `\RuntimeException` with `\LogicException`, run filtered test) | **test failed** as expected (`RuntimeException: stat failed for .../file351.php`), confirming the guard is load-bearing |
| `git status --short` after mutation restoration | **empty** (working tree clean) |

---

## 6. Candidate knowledge-base entries

**None proposed.** The change is a focused performance fix with a well-tested edge-case guard. No new architectural pattern or recurring pitfall emerged that isn't already covered by existing entries (DEC-014 for bounded caches, FAQ-018 for long-running state, FAQ-006 for watcher test strategies).

---

## 7. Remaining risk areas checked clean

- **Long-lived-worker state:** The `$iterators` array is bounded by the number of source dirs (typically 2: `src/` + `config/`) and cleared on sweep completion (`unset` at line 87) and on reload (`resetSweep()` at line 109). No unbounded growth.
- **Reference cycles in closures:** `start()` uses `$this->checkFileSystemChanges(...)` as a first-class callable — no closure capturing `$this` in a way that creates a cycle. The worker owns the watcher, and the timer callback references the method, not a closure with `$this` capture.
- **Process supervision:** No fork/signal changes in this diff. The `reload()` call triggers SIGUSR1 via `Utils::reload()` (unchanged).
- **BC breakage:** No public interface changes. `PollingMonitorWatcher` is a `private`-method-only class. `getIterators()` is a test-only helper on the test class, not on the watcher.
- **Security:** No authorization, validation, or data exposure surface in this change. The watcher reads file mtimes from configured source dirs only.
