# Findings — coder (#558)

## Obstacles / surprises

- **The issue's core change was already landed.** `src/Middleware/StaticFilesMiddleware.php`
  contains no `array_shift()` anywhere (grep + `git log -S array_shift`
  both confirm). Commit `ba60a7f` (PR #607, closes #570) replaced it with
  `unset($cache[array_key_first($cache)])` in a new `cacheStore()` helper
  (`src/Middleware/StaticFilesMiddleware.php:293-307`) while fixing the
  unbounded negative-cache growth. The issue's line references (:174-230,
  :225-227) describe a pre-#607 revision. Work on #558 that assumed the old
  shape would have re-litigated an already-shipped fix; the real remaining
  gap was test coverage: **no test asserted LRU semantics** (least-recently-
  *used* victim), only boundedness (`testSymlinkNegativeCacheRespectsMaxSize`).
  Suggest for the issue/PR text: frame #558 as the follow-through (tests +
  measurement), not the fix itself.

- **`array_key_first` on PHP arrays is O(1), but the append is not free.**
  My scaling measurement on a 100k-entry array showed the combined
  evict+append op at 2.6 µs/op vs 0.2 µs/op at 1024 entries. The growth is
  dominated by the hash-table insert of the new entry (possible bucket
  resize), not by the eviction — `array_shift` at the same size costs
  49 µs/op and grows linearly. At the real `CACHE_MAX_SIZE` (1024) the
  eviction is flat O(1). No production impact; worth knowing if anyone
  misreads the 100k figure.

- **The cache is a process-wide static shared across all tests AND all
  middleware instances** (method-local `static $cache` in
  `getRealPathCache()`, `src/Middleware/StaticFilesMiddleware.php:229-236` —
  since round 1 extracted into `src/Middleware/StaticFilesRealPathCache.php`,
  DEC-014 pattern),
  and `StaticFilesMiddlewareTest::setUp()` reuses the same root directory
  (`tests/StaticFilesMiddlewareTest.php:22-24`), so every test in the class
  shares one cache-index space. Any test that asserts on "the" cache must
  account for foreign entries from earlier tests. They are provably bounded
  (≤ `CACHE_MAX_SIZE`, enforced on every insert), which is exactly what makes
  my LRU test deterministic — but a future test that asserts a *small*
  absolute count (e.g. `assertSame(1, count($cache))`) would be flaky.

## Bugs / weak spots noticed (inside and outside this issue's scope)

- `tests/StaticFilesMiddlewareTest.php:459-464` (my new test) uses
  `assertArrayNotHasKey` to prove eviction. It can only catch a *mispriced*
  victim if the victim's path is not coincidentally re-cached between eviction
  and assertion — here the last insert is `/lru-evictor.css`, distinct from
  both probed paths, so the assertion is sound. Fine as written.

- `benchmarks/StaticFilesMiddlewareBench.php:1-160` — the bench has no
  high-cardinality / unique-URL scenario, which is precisely the workload that
  motivated #570 and #558. A `benchThrashingUniquePaths()` case (e.g. 20k
  distinct missing URLs, `@Revs` tuned so the entries outlive `CACHE_MAX_SIZE`)
  would let regressions in eviction cost be caught in CI-style bench runs.
  Suggested fix: add the case with the middleware built on a fresh temp root,
  asserting nothing — bench only.

- `src/Middleware/StaticFilesRealPathCache.php` (storage extracted from
  `StaticFilesMiddleware::getRealPathCache()` after review round 1, FR-005):
  the static cache is keyed per (path, followSymlinks, rootRealPath), but the
  static itself never resets — in the test process, cache entries survive
  across middleware instances and tests (by design). In production each class
  copy is per-worker (the middleware is constructed once), so no issue;
  noted only because it makes unit tests share state, which is surprising to
  a reader.

- `src/Middleware/StaticFilesMiddleware.php:135-148` (`isFilePathBlocked` /
  `isComponentBlocked`) — unchanged, but worth recording: `pathinfo()` +
  the manual dot-chain walk run on *every* served file per request
  (documented hotspot in the bench class docblock). Not touched here; the
  pre-filter idea from #558 would interact with these rules (see
  code-decision-1.md). If it is ever revisited, the allowlist check
  (`isComponentBlocked` last return) is already a candidate hoist: checking
  the final component's extension before the filesystem probe would skip
  `joinPaths()` + `is_link()` walk + `realpath()` for non-allowlisted paths.

- `src/Http/HttpRequestHandler.php:94-104` (`withRootDirectory()`) — the
  issue asks to confirm whether appending the static middleware as the
  innermost layer is intentional (static assets run the full user middleware
  chain first). I did not verify or change this; it is a behavioral question
  for a separate discussion, not a bug.

## Benchmark evidence (PHP 8.5.9, throwaway script, replication of issue #558's methodology)

```
isolation, 1025    entries: array_shift 0.965 us/op | unset(array_key_first) 0.205 us/op   (~4.7x)
isolation, 100000  entries: array_shift 49.368 us/op | unset(array_key_first) 2.592 us/op
same URL each request (warm cache hit):  1.944 us/op
unique URL each request (cache thrash):  3.261 us/op   (issue's before: 3.936 us/op)
eviction @ 1025 entries: 0.191 us/op | @ 100k entries: 2.539 us/op (flat; growth is the hash insert, not the evict)
```

The issue's isolation numbers (1.020 → 0.210) reproduce within ~5%. The
thrash workload shows ~17% improvement over the issue's before-number,
consistent with the issue's estimate that eviction was ~a quarter of the
per-request cost. Eviction is O(1) at the real cache size and amortized
bounded (one O(1) eviction per insert once full).
