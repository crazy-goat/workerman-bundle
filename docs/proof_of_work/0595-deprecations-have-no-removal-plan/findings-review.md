# Findings — Review (issue #595)

One entry per finding. Round 1.

## F-1

- **file:line**: `src/Http/Request.php:120`
- **what is wrong**: The runtime deprecation notice for `Request::withHeader()` was not updated to name a removal version. The `@deprecated` docblock (line 112) now says "This method will be removed in 1.0", but the actual `trigger_error(..., E_USER_DEPRECATED)` message (line 120) still reads only "Since crazy-goat/workerman-bundle 0.23.0: %s::withHeader() is deprecated, use setHeader() instead." — no removal version. The issue's "What to change" explicitly requires the removal version stated in three places: the `@deprecated` annotation, the `setDeprecated()`/`trigger_deprecation()` (runtime) message, and `UPGRADE.md`. The runtime message is what users actually see at runtime; the docblock is only visible in an IDE. This is inconsistent with the other two deprecations, whose runtime messages (`setDeprecated` in ConfigurationTreeBuilder, `trigger_deprecation` in Utils) now all say "Will be removed in 1.0".
- **severity**: medium
- **what happened to it**: FIXED (round 1.5). The runtime message at src/Http/Request.php:120 now reads "...use setHeader() instead. Will be removed in 1.0.", and `testWithHeaderTriggersDeprecation` (tests/RequestTest.php) now additionally asserts `assertStringContainsString('Will be removed in 1.0', $deprecationMessage)`. Both tests pass (`tests/RequestTest.php tests/DependencyInjection/ConfigurationTreeBuilderTest.php`: 33 tests, 88 assertions, OK). Round-1 finding resolved in the fix commit.

## Round-2 verification (commit 756ea34)

- **F-1 CONFIRMED FIXED.** `src/Http/Request.php:120` runtime `trigger_error` message now ends
  "...use setHeader() instead. Will be removed in 1.0."; `tests/RequestTest.php`
  `testWithHeaderTriggersDeprecation` now asserts `assertStringContainsString('Will be removed in 1.0', $deprecationMessage)`.
  Consistent with ConfigurationTreeBuilder `setDeprecated()` and Utils `trigger_deprecation()` (all "Will be removed in 1.0").
  No new findings in round 2.
