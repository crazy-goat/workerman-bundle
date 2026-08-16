# Review — round 2, issue #574

Branch: `feat/issue-574-responseconverter-header-name-normalisat`
Scope: verify round 1 dispositions (fix commits 2253de9, 46225b4) against the actual code, then re-review `git diff origin/master...HEAD` for new issues.

## Round 1 verification

| # | Round 1 claim | Verified status | Evidence |
|---|---|---|---|
| F1 | fixed — CHANGELOG entry added | **Confirmed fixed** | CHANGELOG.md diff adds a `### Fixed` entry under `[Unreleased]` describing the bounded cache, 512-entry cap, `unset` + `array_key_first` eviction, 128-byte plausibility skip, preserved corrections, and links issue #574. All four details match the code (HEADER_CACHE_MAX_SIZE = 512, HEADER_NAME_MAX_BYTES = 128, eviction mechanism in HeaderNameNormalizer.php:75-77, CORRECTIONS table at :44-49). Accurate. |
| F2 | fixed — benchmark numbers recorded | **Confirmed fixed (as far as repo-verifiable)** | Numbers (hit 263 ns/op vs old 421 ns/op, ~160 ns reflection overhead) recorded at findings-review.md:68. The PR-description half of the criterion is not verifiable from the repo; the disposition note states the numbers go there too. |
| F3 | fixed — constant public + referenced in test | **Confirmed fixed** | `HEADER_NAME_MAX_BYTES` is `public` (src/Http/Response/HeaderNameNormalizer.php:42); the test's foreach bound is now `assertLessThanOrEqual(HeaderNameNormalizer::HEADER_NAME_MAX_BYTES, ...)` (tests/ResponseConverterTest.php, testConvertSkipsCachingImplausiblyLongHeaderNames). No hardcoded 128 remains in the test. |
| F4 | accepted deviation, not fixed | **Unchanged, by design** | The flood test still asserts only `count(cache) <= HEADER_CACHE_MAX_SIZE`; no `memory_get_usage()` assertion. This matches the recorded rationale (entry bound ⇒ memory bound; memory deltas are env-noisy). No action. |
| F5 | rejected — FQCN matches file-wide style | **Rejection upheld — not a real finding** | Measured: 20 occurrences of `\Symfony\Component\HttpFoundation\Response::HTTP_*` in tests/ResponseConverterTest.php, 0 short-form `Response::HTTP_*` usages. The disposition's claim (19 pre-existing + new tests following suit) is true; changing only the new tests would create the inconsistency the nit complained about. |
| F6 | fixed — gate on strlen($lower) | **Confirmed fixed** | src/Http/Response/HeaderNameNormalizer.php:68 — `if (strlen($lower) > self::HEADER_NAME_MAX_BYTES)`. Gate now measures the same string that keys the cache slot. |
| F7 | fixed — tearDown added | **Confirmed fixed** | tests/ResponseConverterTest.php:29-32 — `tearDown(): void` calling `HeaderNameNormalizer::resetCache()`, alongside the existing setUp reset. |

## Tests

`php vendor/bin/phpunit --filter ResponseConverterTest` → **OK, 29 tests, 73 assertions** (1 warning: XDEBUG_MODE=coverage not set — environmental, unrelated).

## Lint

`composer lint` (Rector dry-run) reports 2 files: `src/DTO/RequestConverter.php:326` and `tests/ProcessInspectorTest.php:931` (`NullToStrictStringFuncCallArgRector`). **Neither file is touched by this branch** — pre-existing debt, not a regression of this diff. No action required here.

## New findings (round 2)

### N1 — findings-review.md has the round 1 dispositions block duplicated 6 times

- **File:** docs/proof_of_work/0574-responseconverter-header-cache/findings-review.md:65-147
- **Severity:** low (docs hygiene)
- **What's wrong:** The identical "Round 1 dispositions (main session, step 5)" block appears six times in a row, separated by `---` rules. Clearly an append-loop artefact of the main session's step 5. Content is accurate but the repetition is noise and will compound if future rounds append the same way.
- **Suggested fix:** collapse to a single block (main session; I am read-only on this file's existing content per instructions).

Nothing else new: the HeaderNameNormalizer extraction preserves the exact normalisation semantics (corrections table + `implode('-', array_map(ucfirst(...), explode('-', $name)))` — identical expression to the removed ResponseConverter code, now operating on `$name` via the same `$lower` lookup key); eviction is insert-time with `unset(array_key_first())` per #558; test affordances are `@internal`-documented; no comments, style, or security issues found in the new files.

## Verdict

All round 1 fixes verified in code; one new low-severity docs finding (N1). Branch is approve-with-nit.
