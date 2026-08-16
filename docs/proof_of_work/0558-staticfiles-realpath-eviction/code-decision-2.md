# Code decision 2 — review round 1→2 fixes (#558)

## What was done

Round 1's review (Approve) raised five findings. All five were answered —
four fixed, one resolved by the main session's earlier tree cleanup:

- **FR-001 (medium, working-tree hygiene):** unresolved conflict markers from
  `git stash pop` of an unrelated branch (`perf/polling-monitor-sharded-scan`,
  issue #559 territory) had leaked into the working tree during review round
  1 (a `git stash` / `git stash pop` sequence by the review subagent popped
  the pre-existing stash entry). Resolved **before** any commit: reverted the
  two files to HEAD, verified the stash entry is preserved (the #559 WIP is
  intact), and confirmed `git status` clean. Nothing from that stash ever
  entered this PR.
- **FR-002 (low, stale line numbers in proof-of-work docs):** fixed —
  `code-decision-1.md` and `findings-coder.md` now cite the current lines
  (`cacheStore()` at `StaticFilesMiddleware.php:293-307`) and the new
  `StaticFilesRealPathCache` location.
- **FR-003 (low, wrong test-root path in docs):** fixed — `tests/App/data/public`
  → `tests/data/public` in `code-decision-1.md`.
- **FR-004 (nit, "flat" overstates 100k behavior in CHANGELOG):** fixed — the
  entry now says the *eviction* stays O(1) while noting the remaining growth
  at 100k entries is the hash-table insert, not the eviction.
- **FR-005 (low, no reset affordance; reflection on a method-local static):**
  fixed per DEC-014 — see below.

## FR-005: extraction of the cache storage per DEC-014

DEC-014's trigger is "adding or reviewing a process-lifetime static/FIFO
cache keyed by data the bundle does not control", its house pattern is
"public test affordances (`::cache()` / `::resetCache()`) so the bound is
assertable without reflection on a method-local static" — and the new LRU
test's reflection on `StaticFilesMiddleware::getRealPathCache()` was exactly
the anti-pattern the decision exists to remove.

`StaticFilesMiddleware` is `final readonly`, so the static cannot move to a
class-level property (PHP rejects static properties in readonly classes;
DEC-014). The storage was therefore extracted into a new `@internal` final
class `src/Middleware/StaticFilesRealPathCache` with `::all()` (by-reference
view for the middleware's existing `cacheStore()` write path),
`::cache()` and `::resetCache()` test affordances — the same shape
`HeaderNameNormalizer` uses in `src/Http/Response/HeaderNameNormalizer.php`.

The middleware change is a pure move: `resolveRealPath()` now takes the cache
by reference from `StaticFilesRealPathCache::all()` instead of the removed
`getRealPathCache()` method; keys, cap, TTL and eviction are untouched.
`StaticFilesMiddlewareTest::setUp()` now calls `resetCache()`, giving every
test an empty cache (house pattern, matches `ResponseConverterTest`), and
the reflection sites that asserted on the cache were migrated to the
affordance (`::cache()`). The `rootRealPath` reflection in the LRU test was
replaced with `realpath($this->rootDirectory)`, which is exactly what the
constructor stores.

## What I rejected

- **Leaving FR-005 "deliberately not fixed".** Defensible on its own (the
  test is deterministic either way, the cap invariant bounds foreign
  entries), but the review cited DEC-014 and the trigger covers this cache
  precisely; the fix is ~35 lines of behavior-neutral extraction plus a
  test-migration that simplifies the test. Cost of saying no was a documented
  house pattern left unapplied in the exact code it was written for.
- **Migrating every reflection site in the test file.** `isComponentBlocked()`,
  `joinPaths()`, `isFilePathBlocked()` reflection stays — those exercise
  private *behavior* and have no affordance (and should not get one). Only
  the three `getRealPathCache()` reflection sites were migrated, because that
  is what the affordance replaced.
- **Making the affordances public API.** They stay `@internal` with the same
  "Test affordance only" contract as `HeaderNameNormalizer::cache()` /
  `resetCache()` — test convenience, not production surface.

## What I was unsure about

- Whether `resetCache()` in `setUp()` could change the outcome of tests that
  implicitly relied on shared cache state. The full middleware suite passing
  with the round-1 test's 1025-entry fill (before this extraction) already
  proved every test tolerates arbitrary initial cache state; with
  `setUp()` reset they now get a *deterministic* empty start, which is
  strictly safer. Test order independence improves.
- `realpath($this->rootDirectory)` vs reflection on `rootRealPath`: identical
  values (the constructor stores `realpath($rootDirectory)` for non-phar
  roots) and removes a reflection from the new test; the `'0'` followSymlinks
  flag matches the constructor default the test uses.
