# Review 1 — #558 StaticFilesMiddleware realpath-cache eviction LRU test

**Reviewer:** review agent (round 1)
**Branch:** `perf/issue-558-staticfilesmiddleware-realpath-cache-use`
**Commit:** `8848d8a`
**Committed diff:** `git diff origin/master...HEAD` — 4 files, +269 lines
**Date:** 2026-08-16

---

## 1. Earlier findings

This is round 1. `docs/proof_of_work/0558-staticfiles-realpath-eviction/findings-review.md` did not exist before this review. No earlier open findings to revisit.

## 2. Verdict

**Approve.** The committed change delivers exactly what the issue's remaining scope called for: a deterministic LRU-semantics regression test, a CHANGELOG Performance entry with measured numbers, and proof-of-work docs. The core claim is verified — no `array_shift()` remains in `src/Middleware/StaticFilesMiddleware.php`; eviction uses `unset(array_key_first())` in `cacheStore()` (`:289-307`) bounded at `CACHE_MAX_SIZE = 1024` (`:60`). The new test correctly distinguishes LRU from FIFO eviction, is deterministic despite the process-wide static cache shared across tests, and does not break any existing test (121/121 pass). The CHANGELOG numbers are consistent with the benchmark evidence in `findings-coder.md`. Five minor findings (1 medium, 3 low, 1 nit) — none are blockers; the medium is a working-tree hygiene issue unrelated to the committed diff.

## 3. Verification of the issue's core claim

| Claim | Status | Evidence |
|-------|--------|----------|
| No `array_shift()` in `StaticFilesMiddleware.php` | ✅ confirmed | `grep -n 'array_shift' src/Middleware/StaticFilesMiddleware.php` → exit 1 (no matches) |
| Eviction is `unset(array_key_first())` | ✅ confirmed | `cacheStore()` at `:289-307`: `unset($cache[$oldest])` where `$oldest = array_key_first($cache)` |
| `CACHE_MAX_SIZE = 1024` | ✅ confirmed | `:60`: `private const CACHE_MAX_SIZE = 1024;` |
| Cap enforced on every insert | ✅ confirmed | `cacheStore()` is the single write path; `if (count($cache) > self::CACHE_MAX_SIZE)` runs on every call |
| LRU touch on cache hit | ✅ confirmed | `:259`: hit within TTL calls `cacheStore($cache, $cacheIndex, $cached['path'], $cached['time'])` which does `unset` + re-append, moving entry to MRU end |

## 4. Findings

| ID | file:line | description | severity |
|----|-----------|-------------|----------|
| FR-001 | tests/PollingMonitorWatcherTest.php, src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php (working tree) | Unmerged merge-conflict markers from a `git stash pop` on an unrelated branch. Not in the committed diff, but blocks local `composer lint` / `composer test`. | medium |
| FR-002 | docs/proof_of_work/0558-staticfiles-realpath-eviction/code-decision-1.md:12, findings-coder.md:7 | Stale source line numbers (`:215-225`, `:229-236`) — actual lines are `:289-307` and `:324-330`. | low |
| FR-003 | docs/proof_of_work/0558-staticfiles-realpath-eviction/code-decision-1.md:44 | Wrong test root-directory path: says `tests/App/data/public`, actual is `tests/data/public`. | low |
| FR-004 | CHANGELOG.md:57 | "flat" overstates 100k-entry behavior (2.6 µs is 13× the 1024-entry cost); the eviction is O(1) but the combined evict+append is not flat at 100k. | nit |
| FR-005 | tests/StaticFilesMiddlewareTest.php:413-466 | LRU test leaves 1024 entries in the process-wide static cache; no reset method exists. Latent risk for future tests asserting small absolute counts. | low |

**Automated-check opportunities:**
- FR-001: a pre-push hook that rejects files containing `<<<<<<<` / `=======` / `>>>>>>>` conflict markers would catch this class.
- FR-002/FR-003: a doc-lint rule that verifies `file:line` and `path/` references against the actual filesystem would catch stale references.

## 5. Answers on specific review points

### 5.1 LRU test correctness — is it deterministic?

**Yes.** The cache is a process-wide static (`getRealPathCache()`, method-local `static $cache`), shared by all `StaticFilesMiddleware` instances and therefore by all tests in the class. The test is deterministic because of the invariant the production code guarantees: **every insert enforces `CACHE_MAX_SIZE`**. Regardless of how many foreign entries the cache holds at test start (0 to 1024), inserting `CACHE_MAX_SIZE + 1` = 1025 fresh unique entries evicts every foreign entry first, then evicts one of the new entries (`lru-pad-0`). The survivors are exactly `lru-pad-1` through `lru-pad-1024` = 1024 entries, in insertion order. This holds for any initial cache state.

### 5.2 Does the test prove LRU, not FIFO?

**Yes.** The test's key insight is the touch path:
1. Fill cache past cap → survivors are `lru-pad-1` … `lru-pad-1024` (lru-pad-0 evicted during fill).
2. Hit `oldest = lru-pad-1` (the first survivor) — `resolveRealPath()` finds the cached entry, TTL not expired, calls `cacheStore()` which does `unset` + re-append, moving `lru-pad-1` to the MRU end.
3. Insert `/lru-evictor.css` — cache exceeds cap, `array_key_first` now returns `lru-pad-2` (the new oldest), which is evicted.
4. Assert: `lru-pad-1` survives ✅, `lru-pad-2` evicted ✅.

Under pure FIFO (mutation: return cached path without re-inserting on hit), step 2 would not move `lru-pad-1`, so step 3 would evict `lru-pad-1` instead of `lru-pad-2`, and `assertArrayHasKey($indexOf($oldest), $cache())` would **fail**. The mutation-verification claim is correct.

### 5.3 Key composition match

The test's `indexOf()` builds: `$path . "\0" . '0' . "\0" . $rootRealPath`. The source (`:252`) builds: `$cacheKey . "\0" . ($this->followSymlinks ? '1' : '0') . "\0" . $this->rootRealPath`. The middleware is constructed with `followSymlinks = false` (default), so the flag is `'0'` — matches. The `rootRealPath` is read via reflection from the middleware instance — matches. ✅

### 5.4 TTL concern (CACHE_NEGATIVE_TTL = 5)

**Not a risk.** The entries are negative (missing files, `path = false`, TTL = 5s). The test creates 1025 requests + 1 hit + 1 evictor = 1027 `realpath()` calls for non-existent files. At ~1–10 µs each, the total fill time is well under 5 seconds. Even if the fill exceeded 5s, the test would still pass: a stale hit unsets the entry and re-inserts it (via `cacheStore` after a fresh `realpath()` lookup), which still moves it to the MRU end — the LRU position change is the only thing the test asserts. ✅

### 5.5 Foreign positive entries from earlier tests

Positive (served-file) entries go through the identical `cacheStore()` path. A foreign positive entry in the cache is treated the same as a negative one for eviction purposes — it occupies a slot and is evicted by `array_key_first` when the cap is exceeded. The test's fill of 1025 entries evicts all foreign entries regardless of type. ✅

### 5.6 Cache pollution breaking other tests

**No.** Ran the full `tests/StaticFilesMiddlewareTest.php` suite (121 tests, 237 assertions) — all pass. The existing boundedness test (`testSymlinkNegativeCacheRespectsMaxSize`) uses `assertLessThanOrEqual($maxSize, ...)` and relative size comparisons, not absolute counts. The TTL test (`testSymlinkRejectionHitKeepsFixedTtl`) checks its own entry's presence and timestamp, not the total count. No test asserts a small absolute cache count. ✅

### 5.7 Mutation-verification claim

**Correct.** Without the LRU touch re-insert (replacing `:259`'s `return $this->cacheStore(...)` with `return $cached['path'];`), the hit on `oldest` would not move it to MRU end, so the subsequent evictor insert would evict `oldest` instead of `secondOldest`, causing `assertArrayHasKey($indexOf($oldest), $cache(), '...')` to fail. Reasoned through, not executed (read-only review).

### 5.8 CHANGELOG entry

- **Numbers consistent with evidence:** CHANGELOG says 0.965 → 0.205 µs/op (~4.7×), 49.4 → 2.6 µs/op at 100k, 3.936 → 3.261 µs/op. `findings-coder.md` says 0.965, 0.205, 49.368, 2.592, 3.261. All consistent (CHANGELOG rounds appropriately). ✅
- **Keep a Changelog format:** Under `## [Unreleased]` → `### Performance`, follows the same style as the #557 entry above it. ✅
- **Placement:** Correct — `[Unreleased]` → `### Performance`, after the existing #557 entry. ✅
- **Overlap with #570/#607 Fixed entry:** The existing Fixed entry in `[0.26.0]` documents the mechanism (`unset(array_key_first())` instead of `array_shift()`). The new Performance entry adds measured numbers and the LRU test. Different sections, different releases. Acceptable — the coder acknowledged this in `code-decision-1.md` and offered a two-line revert if reviewers find it redundant. ✅

### 5.9 Proof-of-work docs accuracy

- The O(1) claim at 100k entries is nuanced: the eviction itself is O(1), but the combined evict+append is 2.6 µs at 100k vs 0.2 µs at 1024 (13× growth from hash-table resize). `findings-coder.md` is honest about this; `code-decision-1.md` and the CHANGELOG could be clearer. See FR-004.
- Stale line numbers in both docs (FR-002).
- Wrong test directory path (FR-003).
- Benchmark methodology: throwaway script replicating the issue's methodology, same PHP version (8.5.9). Numbers reproduce within ~5% of the issue's isolation figures. Sound for the purpose. ✅

### 5.10 Type correctness, error handling, PSR-12

- The test uses `assert(is_array($cache))` and `assert(is_int($maxSize))` for PHPStan type narrowing — consistent with the existing test patterns in this file. ✅
- No error handling issues — the test exercises a well-defined path (missing files → negative cache). ✅
- PSR-12 compliance — the test follows the same formatting as surrounding tests. ✅

### 5.11 Missing tests / outdated docs

- **Positive-entry LRU:** The test only exercises negative entries (missing files). Positive-entry LRU ordering goes through the identical `cacheStore()` path, so the test transitively covers it. A dedicated positive-entry test would be more thorough but is not strictly necessary.
- **No `resetCache()` public affordance:** The `getRealPathCache()` static has no public reset method, unlike the `HeaderNameNormalizer::resetCache()` pattern established in DEC-014. This makes cache-state assertions harder for future tests (FR-005). A `resetCache()` method would align with the house pattern.

## 6. Knowledge-base decisions consulted

- **DEC-004** (Negative realpath cache is capped) — the cap is enforced; this PR adds the LRU test that DEC-004's original fix lacked. No violation.
- **DEC-014** (Bounded static caches: cap + plausibility skip + test affordance) — the cache has a cap and is tested, but lacks the public `resetCache()` test affordance that DEC-014 recommends. See FR-005.
- **DEC-013** (Optimization gates on security-relevant parsers must fail open) — not directly applicable; the realpath cache is not a security parser. The pre-filter rejection (correctly rejected by the coder) would have needed DEC-013 scrutiny.
- **FAQ-004** (File-only rules must be gated on the last path component) — not touched by this PR; the coder correctly cited it as a constraint for the rejected pre-filter idea.
- **FAQ-026** (phpbench report name is `aggregate`, not `average`) — not relevant; no phpbench benchmark was added.

## 7. Candidate knowledge-base entries

### Candidate 1: LRU touch on cache hit preserves timestamp but moves position

- **Title:** `StaticFilesMiddleware` realpath cache: cache-hit re-insert moves entry to MRU end, preserving the original timestamp
- **Tags:** `static-files`, `cache`, `memory`, `tests`
- **Trigger:** testing or modifying the realpath cache eviction order in `StaticFilesMiddleware`
- **Paragraph:** `StaticFilesMiddleware::resolveRealPath()` re-inserts a cache entry on every hit within TTL via `cacheStore()` (`unset` + re-append), which moves the entry to the most-recently-used end of the PHP array while preserving the original `time` field — so TTL stays fixed (a hit does not slide the expiry forward) but LRU position advances. Eviction via `unset(array_key_first())` therefore removes the least-recently-*used* entry, not the least-recently-inserted one. A regression test must exercise the touch path (hit an entry, then force an eviction) to distinguish LRU from FIFO; merely filling past `CACHE_MAX_SIZE` and checking which entry was dropped would pass under both policies.

### Candidate 2: Process-wide static cache test determinism via overfill

- **Title:** Deterministic assertions on a process-wide static cache: overfill by one to evict all foreign entries
- **Tags:** `tests`, `cache`, `memory`, `static-files`
- **Trigger:** writing a test that asserts on the contents of a process-wide static cache shared across test cases
- **Paragraph:** When a static cache (like `StaticFilesMiddleware::getRealPathCache()`) is shared across all tests in a class and has no public reset method, a test that asserts on specific entries or ordering must account for foreign entries from earlier tests. The deterministic approach: insert `CACHE_MAX_SIZE + 1` fresh unique entries — because the cap is enforced on every insert, this provably evicts every foreign entry first, leaving exactly the last `CACHE_MAX_SIZE` entries from this test, in insertion order. The invariant holds for any initial cache state (0 to `CACHE_MAX_SIZE` foreign entries). A future test that asserts a *small* absolute count without overfilling would be flaky; consider adding a public `resetCache()` affordance (DEC-014 pattern) to avoid this class of fragility.

## 8. Gaps in validation / areas checked clean

- **No `array_shift()` anywhere in the middleware** — verified by grep and confirmed by the committed diff not touching the source file.
- **Test passes in isolation and in the full class suite** — 121/121 tests, 237 assertions, 1.1s.
- **No coverage regression** — the new test adds 6 assertions covering the LRU touch + eviction path; no existing coverage is reduced.
- **CHANGELOG numbers reproduce the issue's methodology** — consistent within ~5%.
- **No security-relevant changes** — the cache eviction order is a performance/memory property, not a security gate; DEC-013 does not apply.
- **No BC break** — no public API changes, no source file changes, test-only + docs.
