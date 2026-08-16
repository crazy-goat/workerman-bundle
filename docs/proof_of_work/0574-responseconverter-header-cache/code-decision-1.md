# Code decision 1 — bounding the ResponseConverter header-name cache (#574)

## Approach taken

The unbounded `static $cache` local inside `ResponseConverter::normalizeHeaderName()`
could not simply be promoted to a `private static array` property as the issue
suggested: `ResponseConverter` is a `final readonly class`, and PHP forbids
mutable static properties in readonly classes (verified experimentally —
`Static property cannot be readonly`). So the normalisation logic moved to a
dedicated `@internal final class HeaderNameNormalizer`
(src/Http/Response/HeaderNameNormalizer.php) in the same namespace, which owns:

- `HEADER_CACHE_MAX_SIZE = 512` — cap enforced on **every insert** via
  `unset($cache[array_key_first($cache)])` (per #558: ~0.210 µs vs 1.020 µs for
  `array_shift()`). Eviction happens before insert when
  `count($cache) >= cap`, so the cache never exceeds 512 entries.
- `HEADER_NAME_MAX_BYTES = 128` — names longer than this are normalised every
  time and never cached, mirroring Workerman's `MAX_CACHE_STRING_LENGTH`.
- The corrections table (`ETag`, `Content-MD5`, `WWW-Authenticate`, `DNT`)
  moved unchanged into `HeaderNameNormalizer::CORRECTIONS`.
- Test affordances `HeaderNameNormalizer::cache()` / `::resetCache()`,
  following the existing `@internal Test affordance only` convention used by
  `CacheWarmupTimeoutConfig::reset()`.

`ResponseConverter::normalizeHeaderName()` is now a one-line delegation and its
docblock says "at most once while its entry remains cached" instead of
"at most once per worker lifetime".

## What was rejected and why

- **Static property on `ResponseConverter` itself** — impossible in a
  `readonly` class; dropping `readonly` from the class would weaken an
  unrelated invariant to save one file.
- **Instance (non-static) cache property** — would change semantics: the cache
  is deliberately process-wide (shared across converter instances created per
  request by the resetter); scoping it per instance would multiply misses with
  no benefit, since the bound is now enforced.
- **`array_shift()` eviction** — slower (see #558 measurements above).
- **LRU ordering on hit (re-insert to refresh recency)** — pure FIFO eviction
  is what the sibling caches (`StaticFilesMiddleware`) do; LRU adds a write on
  the hot hit path for cardinalities real applications never reach.
- **Invalidating/limiting via `services_resetter`** — the resetter resets
  services, not statics, and the issue only asks for a bound, not lifecycle
  coupling.

## Uncertainties

- 512 vs 256: issue says "256 or 512 is generous"; chose 512 to match
  `StaticFilesMiddleware`'s generosity while staying far below Workerman's
  per-request caches' string-length concerns. Either value is defensible.
- The 10 000-name flood test asserts `<= cap`, not the issue's secondary
  "memory does not grow linearly" clause — that is implied by the hard entry
  bound (each entry ≈ 2 × strlen(name) + hashtable overhead), which is what
  the count assertion pins down.
- Issue also asks for `ResponseConverterBench` before/after numbers in the PR
  description and a CHANGELOG entry — the hit path is unchanged
  (`self::$cache[$lower] ?? ...`), and CHANGELOG is handled by the main
  session per instructions.
