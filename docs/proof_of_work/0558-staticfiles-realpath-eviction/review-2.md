# Review 2 — #558 StaticFilesMiddleware realpath-cache eviction LRU test

**Reviewer:** review-critical agent (round 2)
**Branch:** `perf/issue-558-staticfilesmiddleware-realpath-cache-use`
**Commit:** `ae2b4a7` (on top of `8848d8a`)
**Committed diff:** `git diff origin/master...HEAD` — 9 files, +588 lines
**Date:** 2026-08-16

---

## 1. Round-1 findings disposition

| ID | Disposition | Evidence |
|----|-------------|----------|
| FR-001 | **Fixed** | `git status` clean (only `stash@{0}` WIP on `perf/polling-monitor-sharded-scan` preserved, never entered this PR). PHP CS Fixer (0/247 files), PHPStan level 8 (OK), Rector (OK), PHPUnit 121/121 — all unblocked. |
| FR-002 | **Fixed** (with nit residual — see N2) | `code-decision-1.md:16` and `findings-coder.md:9` now cite `StaticFilesMiddleware.php:293-307` for `cacheStore()` — verified: method signature at line 293, body through ~307. `findings-coder.md:29` still has `229-236` for the removed `getRealPathCache()` (was at `324-330` in `8848d8a`), now annotated "since round 1 extracted into `StaticFilesRealPathCache`" but the line number itself was not corrected. |
| FR-003 | **Fixed** | `code-decision-1.md:42` says `tests/data/public`, matching `setUp()`'s `__DIR__ . '/data/public'` (`tests/StaticFilesMiddlewareTest.php:22`). No `App/` segment. |
| FR-004 | **Fixed** | `CHANGELOG.md:57-68` now says "the eviction itself stays O(1) at every size where `array_shift()` grew linearly" and clarifies "the remaining growth being the hash-table insert, not the eviction". Precise and accurate. |
| FR-005 | **Fixed** | `src/Middleware/StaticFilesRealPathCache.php` extracted per DEC-014: `@internal final class` with `private static array $cache`, `::all()` by-reference, `::cache()` / `::resetCache()` test affordances. `StaticFilesMiddleware::resolveRealPath()` now uses `&StaticFilesRealPathCache::all()` (line 250). Old `&getRealPathCache()` method removed. `setUp()` calls `resetCache()` (line 24). Three `ReflectionMethod(getRealPathCache)` sites migrated to `::cache()`. `grep -rn getRealPathCache src/ tests/` → no matches. |

## 2. Verdict

**Approve.** The round-2 extraction is behavior-neutral and addresses all five
round-1 findings. The new `StaticFilesRealPathCache` class follows the
DEC-014 house pattern (cap + test affordances), and the by-reference `::all()`
return is semantically identical to the old method-local `static $cache` — same
keys, same cap-on-every-insert, same TTL, same eviction victim. PHPStan level 8,
PHP CS Fixer, and Rector all pass clean. 121/121 StaticFilesMiddlewareTest
tests pass; 208/208 combined with HttpRequestHandlerTest pass. Two minor new
findings (1 low, 1 nit) — neither is a blocker.

## 3. New findings

| ID | file:line | description | severity |
|----|-----------|-------------|----------|
| N1 | `src/Middleware/StaticFilesRealPathCache.php` / `src/Middleware/StaticFilesMiddleware.php:293-307` (cacheStore) | DEC-014 plausibility skip not implemented: the realpath cache key includes the request path (unbounded client-controlled length), but there is no max-key-length guard before insert. The HeaderNameNormalizer reference implements `HEADER_NAME_MAX_BYTES = 128` (mirroring Workerman's `MAX_CACHE_STRING_LENGTH`). The cap (1024) bounds the entry count, but each key could be multi-KB. Pre-existing (the cache never had a plausibility skip), but this PR explicitly invokes DEC-014 as the extraction rationale — the pattern lists three pillars (cap + plausibility skip + test affordance) and only two are present. | low |
| N2 | `docs/proof_of_work/0558-staticfiles-realpath-eviction/findings-coder.md:29` | Stale line number `229-236` for the removed `getRealPathCache()` (was at `324-330` in round-1 commit `8848d8a`). The round-2 fix annotated it ("since round 1 extracted into...") but did not correct the line number. Proof-of-work docs only — no code or user-facing impact. | nit |

### N1 — evidence, impact, fix direction, automated check

**Evidence:** DEC-014 (`docs/helpers/decisions.md:216-227`) lists "a plausibility
skip so implausibly long keys never enter the cache (mirroring Workerman's
`MAX_CACHE_STRING_LENGTH`)" as part of the house pattern.
`HeaderNameNormalizer` (`src/Http/Response/HeaderNameNormalizer.php:35-37,
56-58`) implements this as `HEADER_NAME_MAX_BYTES = 128` with
`if (strlen($lower) > self::HEADER_NAME_MAX_BYTES) return $normalized;`.
The realpath cache key is `$cacheKey . "\0" . flag . "\0" . rootRealPath`
(`StaticFilesMiddleware.php:252`) where `$cacheKey` comes from
`$request->path()` — unbounded client input. `cacheStore()`
(`StaticFilesMiddleware.php:293-307`) has no key-length guard.

**Impact:** The cap bounds the entry count at 1024, so memory is bounded.
However, 1024 entries × multi-KB keys = potentially several MB of cache
memory occupied by attacker-sent long URLs. Defense-in-depth gap, not a
correctness or security issue. The pre-filter idea rejected in
`code-decision-1.md` would have addressed this differently; the plausibility
skip is the DEC-014-endorsed minimal guard.

**Smallest safe fix direction:** Add a `CACHE_KEY_MAX_BYTES` constant to
`StaticFilesRealPathCache` (or `StaticFilesMiddleware`) and skip the cache
insert in `cacheStore()` when `strlen($index)` exceeds it, returning `$path`
without storing. ~3 lines, mirrors `HeaderNameNormalizer::cacheMiss()`.

**Automated check:** No existing PHPStan/lint rule catches this. A
KB-lint checklist item tied to DEC-014's trigger ("verify all three pillars:
cap, plausibility skip, test affordance") would.

### N2 — evidence, impact, fix direction, automated check

**Evidence:** `findings-coder.md:29` reads
`` `getRealPathCache()`, `src/Middleware/StaticFilesMiddleware.php:229-236` —
since round 1 extracted into `src/Middleware/StaticFilesRealPathCache.php` ``.
`git show 8848d8a:src/Middleware/StaticFilesMiddleware.php` shows
`getRealPathCache()` at line 324 (method signature), not 229. The method
no longer exists in `ae2b4a7`.

**Impact:** None — proof-of-work docs, not code or user-facing.

**Fix direction:** Replace `229-236` with `324-330` (the round-1 location),
or remove the line number entirely since the method is gone and the
annotation already says "extracted into".

**Automated check:** A doc-lint rule verifying `file:line` references
against the cited file (same as FR-002's recommendation).

## 4. Focus-check answers

### 4.1 Behavior neutrality of the extraction

**Proven neutral.** The only production code change is
`$cache = &$this->getRealPathCache()` → `$cache = &StaticFilesRealPathCache::all()`
(`StaticFilesMiddleware.php:250`). Both return a reference to a process-wide
static array:

- **Old:** `private function &getRealPathCache(): array { static $cache = []; return $cache; }`
  — function-local static, initialized once per process, one call site.
- **New:** `public static function &all(): array { return self::$cache; }` where
  `private static array $cache = []` — class-level static, initialized once per
  process, one call site.

Both bind `$cache` as a reference to the underlying storage. Mutations via
`cacheStore(array &$cache, ...)` (`unset` + append + cap enforcement) affect
the same single store. Key construction (`$cacheKey . "\0" . flag . "\0" .
rootRealPath`, line 252) is unchanged. `CACHE_MAX_SIZE`, `CACHE_TTL`,
`CACHE_NEGATIVE_TTL` constants are on the middleware, unchanged. The eviction
path (`unset($cache[array_key_first($cache)])` in `cacheStore()`) is unchanged.
TTL check (`$now - $cached['time'] < $ttl`) is unchanged. The LRU touch (hit
within TTL re-inserts via `cacheStore`, preserving `$cached['time']`) is
unchanged.

**PHP reference semantics of `&self::$cache` through a public static method:**
identical to returning `&` from a function-local static. The caller must use
`=&` to receive the reference (the middleware does: `$cache =
&StaticFilesRealPathCache::all()`). Without `&`, the caller gets a by-value
copy. `foreach`/iteration on the returned reference preserves insertion order
(PHP arrays are ordered hash maps). `array_key_first()` returns the
first-inserted key. No semantic difference. ✅

### 4.2 PHPStan level 8 compliance

**Clean.** `vendor/bin/phpstan analyse src/Middleware/StaticFilesRealPathCache.php
src/Middleware/StaticFilesMiddleware.php tests/StaticFilesMiddlewareTest.php`
→ `[OK] No errors`. The new file has precise `@var` and `@return` type
annotations matching the `array<string, array{path: string|false, time: int}>`
shape. The `&all()` return type is typed as `array` with the same PHPDoc shape
as the old `&getRealPathCache()`. ✅

### 4.3 resetCache() in setUp() — cross-test impact

**No test breaks; strictly safer.** Every test in `StaticFilesMiddlewareTest`
now starts with an empty cache (`setUp()` line 24). Previously, tests inherited
whatever state prior tests left. Three tests assert on cache state:

- `testSymlinkNegativeCacheRespectsMaxSize` (line 357): uses
  `assertLessThanOrEqual($maxSize, ...)` and relative batch comparisons.
  Starts clean, still passes. ✅
- `testEvictionRemovesLeastRecentlyUsedEntry` (line 413): relies on clean
  state for deterministic survivor ordering. Now guaranteed by `resetCache()`.
  ✅
- `testSymlinkRejectionHitKeepsFixedTtl` (line 459): checks its own entry's
  timestamp, not total count. Starts clean, still passes. ✅

**Other test classes:** `grep -rn StaticFilesMiddleware tests/` shows only
`HttpRequestHandlerTest` references it (lines 347, 1154), indirectly via
`withRootDirectory()`. That test does not assert on cache state. No other test
class constructs `StaticFilesMiddleware` or references `StaticFilesRealPathCache`.
`HttpRequestHandlerTest::setUp()` does not call `resetCache()`, but since it
doesn't assert on cache state, inherited entries from
`StaticFilesMiddlewareTest` (if it runs first in the same PHPUnit process)
are harmless. ✅

### 4.4 LRU test still proves least-recently-used eviction

**Yes.** The touch path in `resolveRealPath()` is unchanged (line 256-259):
on a cache hit within TTL, `cacheStore($cache, $cacheIndex, $cached['path'],
$cached['time'])` does `unset($cache[$index])` + `$cache[$index] = [...]`,
moving the entry to the MRU end while preserving the original timestamp.

The test (lines 413-455):
1. Fills cache with 1025 unique missing paths → survivors are `lru-pad-1`
   through `lru-pad-1024` (1024 entries, insertion order). `lru-pad-0` evicted
   during fill.
2. Hits `oldest = lru-pad-1` → re-inserted at MRU end (timestamp preserved).
3. Inserts `/lru-evictor.css` → cache exceeds cap, `array_key_first()` returns
   `lru-pad-2` (new oldest), evicted.
4. Asserts: `lru-pad-1` survives ✅, `lru-pad-2` evicted ✅, count = 1024 ✅.

Under FIFO (no re-insert on hit), step 2 would not move `lru-pad-1`, so step 3
would evict `lru-pad-1` instead of `lru-pad-2`, and
`assertArrayHasKey($indexOf($oldest), ...)` would fail. The test correctly
distinguishes LRU from FIFO. ✅

The `indexOf` closure (line 423) uses `realpath($this->rootDirectory)` instead
of the old reflection on `rootRealPath`. The middleware constructor stores
`$this->rootRealPath = realpath($rootDirectory)` for non-phar roots
(`StaticFilesMiddleware.php:83-84`). Since `setUp()` creates the directory
before the test runs and `realpath()` on an absolute path is deterministic,
`realpath($this->rootDirectory)` === `$middleware->rootRealPath`. The
`'0'` followSymlinks flag matches the default constructor argument. ✅

### 4.5 @internal affordances — contract and call sites

**Correct.** The class is `@internal` (class-level docblock, line 7-14) with
accurate rationale (readonly class cannot own a static → extract utility class,
DEC-014). `::all()` is public (must be, for the middleware to call) with no
per-method `@internal` — covered by the class-level annotation. `::cache()`
and `::resetCache()` have explicit `@internal Test affordance only. Production
code must not call this.` docblocks, matching `HeaderNameNormalizer`'s pattern.

Production call sites (`grep -rn 'StaticFilesRealPathCache::' src/`):
- `src/Middleware/StaticFilesMiddleware.php:250` → `::all()` only. ✅

Test call sites (`grep -rn 'StaticFilesRealPathCache::' tests/`):
- `tests/StaticFilesMiddlewareTest.php:24` → `::resetCache()` (setUp)
- `tests/StaticFilesMiddlewareTest.php:368` → `::cache()` (boundedness test)
- `tests/StaticFilesMiddlewareTest.php:416` → `::cache()` (LRU test)
- `tests/StaticFilesMiddlewareTest.php:486` → `::cache()` (TTL test)

No production code calls `::cache()` or `::resetCache()`. ✅

### 4.6 CHANGELOG entry accuracy

**Accurate.** `CHANGELOG.md:57-68`, under `## [Unreleased]` → `### Performance`,
after the #557 entry. Keep a Changelog format (bullet under a `###` subsection).
Issue link `[#558]` at the end. Numbers consistent with `findings-coder.md`
benchmark evidence (0.965 → 0.205 µs/op, 49.4 → 2.6 µs/op at 100k, 3.936 →
3.261 µs/op). The wording fix from round 1 is precise: "the eviction itself
stays O(1) at every size where `array_shift()` grew linearly" and "the remaining
growth being the hash-table insert, not the eviction". ✅

### 4.7 Anything else

- **Type correctness:** PHPStan level 8 clean. `assert(is_int($maxSize))` and
  `assert(is_array($cache))` used for PHPStan narrowing, consistent with
  existing patterns. ✅
- **PSR-12:** PHP CS Fixer reports 0/247 files to fix. ✅
- **Rector:** No changes suggested. ✅
- **Missing tests:** The extraction is behavior-neutral and covered by the
  existing 121-test suite. No new test needed for the extraction itself. ✅
- **Outdated docs:** `code-decision-1.md` and `code-decision-2.md` are
  consistent with the current code (line refs verified). `findings-coder.md:29`
  has a stale line number (N2). ✅ (with N2 nit)
- **`tearDown()` does not reset the cache:** Only `setUp()` calls
  `resetCache()`. After the last test in the class, entries persist. No other
  test class asserts on cache state, so this is harmless. A `tearDown()`
  reset would be more thorough but is not necessary. Not a finding. ✅
- **Architectural observation (not a finding):** The cap constant
  (`CACHE_MAX_SIZE`) and eviction logic (`cacheStore()`) live on
  `StaticFilesMiddleware`, while the storage lives on `StaticFilesRealPathCache`.
  In the `HeaderNameNormalizer` pattern, all three are co-located. The
  `::all()` by-reference return means any caller could bypass the cap by
  writing directly. Currently only `cacheStore()` writes, so no bug. The split
  is reasonable given `cacheStore()`'s coupling to middleware logic (entry
  shape, LRU touch, return value). Noted as a residual risk, not a finding.

## 5. Candidate knowledge-base entries

### Candidate 1: By-reference static cache extraction from a final readonly class

- **Title:** Extract a process-lifetime static cache into an `@internal` utility
  class when the owner is `final readonly` — return `&self::$cache` for the
  write path, `::cache()` / `::resetCache()` for tests
- **Tags:** `memory`, `long-running`, `tests`, `static-files`
- **Trigger:** extracting a method-local `static $cache` from a `final readonly`
  class, or reviewing a by-reference cache extraction
- **Paragraph:** A `final readonly class` cannot declare static properties (PHP
  rejects them), so a process-lifetime static cache that must survive across
  instances cannot live on the class itself. The DEC-014 house pattern extracts
  the storage into an `@internal final` utility class with `private static array
  $cache`. The production write path calls `public static function &all(): array`
  and binds with `$cache = &Utility::all()` — the reference makes `unset` +
  append + cap enforcement work unchanged. Test code calls `public static function
  cache(): array` (by-value snapshot for assertions) and `resetCache(): void`
  (clean slate in `setUp()`). This mirrors `HeaderNameNormalizer` and is the
  pattern `StaticFilesRealPathCache` follows. Do not add per-method `@internal`
  to `::all()` — the class-level `@internal` covers it, and `::all()` is the
  production path; reserve per-method `@internal Test affordance only` for
  `::cache()` and `::resetCache()`.

### Candidate 2: DEC-014 plausibility skip for realpath cache keys

- **Title:** The realpath cache in `StaticFilesMiddleware` lacks the DEC-014
  plausibility skip — long request paths occupy cache slots without a
  key-length guard
- **Tags:** `static-files`, `memory`, `long-running`
- **Trigger:** reviewing or extending the realpath cache in
  `StaticFilesMiddleware`, or auditing DEC-014 compliance
- **Paragraph:** DEC-014 lists three pillars for bounded static caches: cap,
  plausibility skip, and test affordances. `StaticFilesRealPathCache` (extracted
  per DEC-014) has the cap (`CACHE_MAX_SIZE = 1024`, enforced in
  `StaticFilesMiddleware::cacheStore()`) and the test affordances
  (`::cache()` / `::resetCache()`), but lacks the plausibility skip. The cache
  key is `path . "\0" . followSymlinks_flag . "\0" . rootRealPath` where `path`
  is the request path — unbounded client-controlled input. The cap bounds the
  entry count, but each key can be multi-KB, so 1024 entries could occupy
  several MB. The `HeaderNameNormalizer` reference implements
  `HEADER_NAME_MAX_BYTES = 128` (mirroring Workerman's
  `MAX_CACHE_STRING_LENGTH`); a similar `CACHE_KEY_MAX_BYTES` guard in
  `cacheStore()` (skip insert when `strlen($index)` exceeds the limit, return
  `$path` without storing) would close the gap. Pre-existing; the extraction PR
  invoked DEC-014 but scoped to the storage move and test affordances only.

## 6. Remaining risk areas checked clean or not fully verified

**Checked clean:**
- Behavior neutrality (keys, cap, TTL, eviction, LRU touch) — proven by code
  inspection and 121/121 + 208/208 test passes.
- PHPStan level 8, PHP CS Fixer, Rector — all clean.
- Cross-test cache isolation — no test relies on shared state; `resetCache()`
  in `setUp()` is strictly safer.
- LRU test correctness — traced the touch path and verified the test
  distinguishes LRU from FIFO.
- `@internal` contract — production calls only `::all()`; tests call only
  `::cache()` / `::resetCache()`.
- CHANGELOG format and accuracy — verified against `findings-coder.md` numbers.
- No BC break — `StaticFilesRealPathCache` is `@internal`, no public API change;
  `StaticFilesMiddleware` public interface unchanged.
- No security regression — the cache eviction order is a performance/memory
  property; DEC-013 (optimization gates on security parsers) does not apply.

**Not fully verified (but low risk):**
- The plausibility-skip gap (N1) is pre-existing and out of this PR's stated
  scope. Flagged for a future follow-up, not a blocker.
- No dedicated positive-entry LRU test (only negative entries exercised). The
  positive and negative paths go through the identical `cacheStore()` method,
  so transitively covered. A dedicated test would be more thorough but is not
  required.
