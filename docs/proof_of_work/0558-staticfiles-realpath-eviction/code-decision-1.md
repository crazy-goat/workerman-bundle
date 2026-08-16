# Code decision 1 — O(1) realpath-cache eviction + LRU semantics test (#558)

## What was done

Issue #558 asked for three things: (1) replace the O(n) `array_shift()`
eviction in `StaticFilesMiddleware::resolveRealPath()` with
`unset(array_key_first())`, (2) prove LRU semantics with a test, (3)
CHANGELOG entry. Optional items (batched eviction, pre-filter for
non-static paths, configurable `CACHE_NEGATIVE_TTL`) were explicitly out of
scope unless compelling.

**Finding on arrival: the eviction change had already landed.** Commit
`ba60a7f` (PR #607, "fix: cap negative realpath cache", closes #570) rewrote
the eviction path into the `cacheStore()` helper, which already enforces
`CACHE_MAX_SIZE` on every insert and evicts via
`unset($cache[array_key_first($cache)])` (`src/Middleware/StaticFilesMiddleware.php:293-307`).
No `array_shift()` remains in the file (verified with `git log -S array_shift`
and a grep). The CHANGELOG `[Unreleased]` → Fixed section already documents
that change and cites #558.

So the remaining deliverables for #558 were: the LRU-semantics test (missing —
no test anywhere asserted that the *least-recently-used*, rather than the
least-recently-inserted, entry is the victim), benchmark verification of the
O(1) claim, and an `[Unreleased]` → Performance CHANGELOG entry carrying the
numbers.

What I changed:

- `tests/StaticFilesMiddlewareTest.php` — new
  `testEvictionRemovesLeastRecentlyUsedEntry()`: fills the cache past
  `CACHE_MAX_SIZE` with unique missing paths, hits the oldest survivor to move
  it to the most-recently-used end, inserts one more unique path to force an
  eviction, and asserts the *previously second-oldest* entry (now the
  least-recently-used) was evicted while the touched entry survives.
- `CHANGELOG.md` — `[Unreleased]` → Performance entry with the measured
  before/after numbers, referencing #558.
- `docs/proof_of_work/0558-staticfiles-realpath-eviction/` — this decision
  file and `findings-coder.md`.

## Design of the LRU test

The cache is a process-wide static (`StaticFilesRealPathCache::all()`,
extracted per DEC-014 because the middleware is `final readonly`), shared by
all instances and therefore by all tests in this class, and the test root
directory is fixed (`tests/data/public`), so the cache index space is
shared. The test is still deterministic because of the invariant the
production code itself guarantees: **every insert enforces the cap**, so at
any moment the cache holds at most `CACHE_MAX_SIZE` foreign entries. Inserting
`CACHE_MAX_SIZE + 1` fresh unique entries therefore evicts every foreign entry
first and guarantees the survivors are exactly the last `CACHE_MAX_SIZE`
entries inserted by this test, in insertion order.

The touch that distinguishes LRU from FIFO is the cache-hit path in
`resolveRealPath()`: a hit within TTL re-inserts the entry via `cacheStore()`
(`unset` + re-append), preserving the original timestamp but moving the entry
to the most-recently-used end. Under pure FIFO (mutation: returning the cached
path without the re-insert) the test fails exactly as intended — verified by
temporarily mutating the source, observing the failure, and restoring it.

## What I rejected, and why

- **Batched eviction (drop the oldest ~64)** — rejected. The primary ask is
  already met: each eviction is a single `unset` + `array_key_first`, both
  O(1), measured ~0.2 µs/op — the eviction *cost is already paid once per
  insert*, which is the amortized optimum for a strict cap (cache oscillates
  between 1024 and 1025 entries). Batching would only save the fixed overhead
  of the count check and `array_key_first` call across inserts — both native
  O(1) ops — while adding a constant, a loop, a different steady-state size
  (960..1024), and a second code path to reason about. The measured upside is
  a few tenths of a microsecond on an already-inexpensive op: not worth the
  complexity, and "keep LRU semantics and stay simple" argues for leaving the
  one-eviction-per-insert shape alone.
- **Pre-filter for non-static paths (extension allowlist before
  `realpath()`)** — rejected, per instruction and per the knowledge base.
  FAQ-004 (`file:line` in `docs/helpers/faq.md`, "File-only rules must be
  gated on the last path component") records that extension rules must apply
  only to the final component, and DEC-013 ("optimization gates ... must fail
  open") demands proof that a fast path never changes which files are
  servable. Getting both right is a real design task (extensionless paths,
  dotfiles, allowlist configured vs not, directory components named
  `foo.css/`) for a gain that only matters on high-cardinality thrash, which
  is already addressed by the eviction fix. Revisit separately if #558's
  remaining workload gap becomes an issue.
- **Configurable / longer `CACHE_NEGATIVE_TTL`** — rejected: no config
  plumbing exists for middleware behavior, changing the default trades
  hot-reload responsiveness for syscalls, and there is no clean testable
  seam. The issue itself marked this "revisit-worthy at best".
- **Benchmark case added to `benchmarks/StaticFilesMiddlewareBench.php`** —
  considered, rejected: the bench has no unique-URL thrash scenario, adding
  one is a new benchmark contract (phpbench annotations, ink of a new method),
  and the issue's before-numbers already exist. I measured with a throwaway
  script instead, replicating the issue's methodology.

## What I was unsure about

- **CHANGELOG duplication.** The Fixed entry from #607 already documents the
  mechanism and cites #558. I chose to add a separate Performance entry
  focused on the measured outcome and the new LRU test rather than editing or
  skipping: the issue's acceptance criteria explicitly require an entry, and
  the perf framing + numbers + test coverage are new content. If reviewers
  consider it redundant with the #570 entry, dropping the new bullet is a
  two-line revert.
- **Reflection-based assertions.** Round 1's review flagged this (FR-005):
  the existing tests use `ReflectionMethod::invoke()` on `getRealPathCache()`
  for size assertions, which is exactly what DEC-014's public `cache()`/
  `resetCache()` affordances exist to avoid. Rounds 1→2 (see
  `code-decision-2.md`) replaced the reflection with
  `StaticFilesRealPathCache::cache()`/`resetCache()`. Behavioral
  distinguishability of a cache hit vs. a re-probe is impossible here (both
  fall through to `$next`), so the affordance is the only way to assert the
  victim.
- **Phar root paths** (`isPharPath()`): the cache can hold positive entries
  too. My test only exercises negative entries (missing files), which is the
  high-cardinality case from the issue; positive-entry LRU ordering goes
  through the identical `cacheStore()` path.
