# Review 1 — issue #574, ResponseConverter header-name cache bound

Reviewer: review-critical. Scope: `git diff origin/master...HEAD` —
`src/Http/Response/HeaderNameNormalizer.php` (new),
`src/Http/Response/ResponseConverter.php`, `tests/ResponseConverterTest.php`,
plus the coder's proof-of-work in this directory.

## Verification performed (independent)

- `vendor/bin/phpunit --filter ResponseConverterTest` — OK (29 tests, 73 assertions).
- `vendor/bin/phpstan analyse` (level per phpstan.neon.dist) on the three touched files — no errors.
- `vendor/bin/php-cs-fixer --dry-run --config .php-cs-fixer.dist.php` — clean.
- Autoload: PSR-4 `CrazyGoat\WorkermanBundle\` → `src/` covers the new class; no DI registration needed (static utility, `@internal`).

## Acceptance criteria check (issue #574)

| Criterion | Status |
| --- | --- |
| Cache bounded by explicit constant, enforced on every insert | **Met.** `cacheMiss()` evicts before insert when `count >= 512`; cap can never be exceeded (insert is the only mutation besides `resetCache()`). |
| 10 000-name flood test asserting cache ≤ cap | **Met** (`testConvertHeaderNameCacheStaysBoundedUnderDistinctNameFlood`). The "memory does not grow linearly" half of the criterion is not literally asserted — see finding F4; the coder's rationale (a hard entry bound implies a hard memory bound) is sound, so this is a documentation-of-deviation matter, not a code defect. |
| Output unchanged incl. corrections table + multi-segment | **Met.** `testConvertCorrectionsAndMultiSegmentNamesSurviveCacheChurn` covers all four corrections and a multi-segment name, both pre- and post-eviction; corrections table moved verbatim. |
| Evicted name normalises identically on re-request | **Met** (`testConvertEvictedHeaderNameNormalisesIdenticallyOnReRequest`), with an explicit `assertArrayNotHasKey` proving the probe was actually evicted before the re-request. |
| Cache reachable from tests without reflection | **Met.** `HeaderNameNormalizer::cache()` / `::resetCache()` public static test affordances, following the existing `@internal Test affordance only` convention. |
| Docblock matches new behaviour | **Met.** Both the new class docblock and the trimmed `ResponseConverter::normalizeHeaderName()` docblock say "while its entry remains cached". |
| `ResponseConverterBench` before/after in PR description | **Not verifiable from the diff** — F2. The hit path is structurally unchanged (`$cache[$lower] ?? miss`), so no regression is expected, but the criterion asks for numbers. |
| CHANGELOG entry under [Unreleased] | **Not met in this diff** — F1. |

## Design assessment

- **Extracting `HeaderNameNormalizer` over a static property on `ResponseConverter`** is correct: `final readonly class` cannot declare mutable statics (PHP limitation, verified by the coder). The alternative — dropping `readonly` — would weaken an unrelated invariant. Sound.
- **Process-wide static sharing** is safe here: Workerman workers are single-threaded event loops; the cache holds only pure functions of the key (normalisation is deterministic), so cross-instance and cross-request sharing cannot produce stale or wrong values. It is deliberately *not* wired to `services_resetter` — correct, since the resetter resets services, not statics, and resetting would only cost re-warmup. Noted for the record; no finding.
- **Hit-path performance**: `normalize()` is `strtolower` + coalesce lookup; all miss/eviction logic is pushed into `cacheMiss()`, keeping the hot path free of extra branches. Eviction via `unset($cache[array_key_first($cache)])` matches the #558 measurement and the `StaticFilesMiddleware` convention. FIFO (not LRU) is consistent with the sibling caches. No finding.
- **PSR-12 / conventions**: file layout, `declare(strict_types=1)`, `final class`, const visibility, first-class callables (`ucfirst(...)`) all match the codebase. cs-fixer confirms.
- **Pre-existing, out of scope** (recorded so it is not lost, matches coder's findings): `extractHeaders()` lowercases the already-normalised name again at `src/Http/Response/ResponseConverter.php:97` — a redundant `strtolower` per header per response. Not introduced by this diff; do not fix here.

## Findings

See `findings-review.md`. Summary: **no high findings.** One medium
(CHANGELOG acceptance criterion unmet in the diff), two low (bench numbers
pending at PR-description time; test hardcodes the 128-byte plausibility limit
instead of referencing a constant), four nits. The core fix — cap on every
insert, eviction identity, long-name skip, corrections preservation,
reflection-free testability — is correct and adequately tested.
