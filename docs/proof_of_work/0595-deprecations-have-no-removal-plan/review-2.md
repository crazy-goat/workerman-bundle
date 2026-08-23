# Review — Round 2 (issue #595)

Reviewed commit `756ea34` ("fix: state removal version in withHeader() runtime deprecation message"),
the fix for round-1 finding F-1.

## F-1 resolution — CONFIRMED FIXED

- `src/Http/Request.php:120` — the runtime `trigger_error(..., E_USER_DEPRECATED)` message now reads
  "Since crazy-goat/workerman-bundle 0.23.0: %s::withHeader() is deprecated, use setHeader() instead. Will be removed in 1.0."
  The removal version is now stated in the runtime message, matching the `@deprecated` docblock (line 112).
- `tests/RequestTest.php` — `testWithHeaderTriggersDeprecation` now additionally asserts
  `assertStringContainsString('Will be removed in 1.0', $deprecationMessage)`.
- Consistency: all three deprecation sites now state "Will be removed in 1.0" in their runtime messages —
  ConfigurationTreeBuilder `setDeprecated()` (lines 137/141/151), Utils `trigger_deprecation()` (line 76),
  and Request `trigger_error` (line 120). No ambiguous wording remains.
- Test verified locally: `vendor/bin/phpunit tests/RequestTest.php --filter testWithHeaderTriggersDeprecation`
  → 1 test, 3 assertions, OK.

## Consistency sweep

- `grep -rn "next major\|next release\|in the next major" src/` → no matches.
- Every `trigger_error` / `trigger_deprecation` / `@deprecated` / `setDeprecated` in `src/` names a concrete
  removal version (1.0). No deprecation site lacks a removal version.

## No regressions / out-of-scope

- `git diff cf9a2c4 756ea34 --name-only` → only `src/Http/Request.php`, `tests/RequestTest.php`, and the two
  POW files (`findings-review.md`, `review-1.md`). No out-of-scope edits smuggled in.

## New-test quality

- The added assertion is a stable substring match on a fixed literal ("Will be removed in 1.0") — not fragile,
  not flaky. It makes the test meaningfully stronger: it now guards the removal-version wording that was the
  round-1 gap, so a silent regression of the message content fails CI.

## New findings

None.

## Verdict

The diff is **CLEAN and READY TO MERGE**. F-1 is resolved; no new issues introduced by the fix.
