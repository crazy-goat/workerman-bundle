# Findings — coder, issue #574

## Obstacles / surprises

1. **`readonly` classes cannot own mutable static state.** The issue's
   suggested fix ("promote to a `private static array` property") is not
   directly applicable to `final readonly class ResponseConverter`
   (src/Http/Response/ResponseConverter.php:12): PHP rejects static properties
   in readonly classes (`Static property cannot be readonly`). Resolved by
   extracting `HeaderNameNormalizer` — see code-decision-1.md.
2. **Symfony auto-adds headers in the flood tests.** Every
   `new Response(...)` carries `Date` and `Content-Type`, so "cache is empty
   after a long-name-only response" is false — the skip-long-names test
   asserts per-key length instead of `assertCount(0, ...)`
   (tests/ResponseConverterTest.php:425-434).
3. The repo's php-cs-fixer config requires `--config .php-cs-fixer.dist.php`
   when passing explicit paths (multi-path invocation errors out without it).

## Bugs / weak spots noticed (out of scope unless noted)

- **In scope, fixed:** unbounded static cache — the issue itself.
- `ResponseConverter::normalizeHeaderName()` is now a pure delegation
  (src/Http/Response/ResponseConverter.php:151-158). Could be inlined at the
  single call site (extractHeaders, :96), but kept as a seam for readability;
  trivially removable later.
- `tests/ContentLengthDesyncTest.php:195` uses reflection into
  `ResponseConverter` internals; if more static/behavioural state is added
  there, that test may need the same test-affordance treatment used here.
- Sibling-cache asymmetry worth a follow-up: the issue text mentions a
  reported **bypass of the `StaticFilesMiddleware` realpath cap**
  (src/Middleware/StaticFilesMiddleware.php:301-306 evicts only one entry per
  insert — fine — but the issue references a separately reported bypass; not
  verified here). Suggested fix: confirm and bound that path the same way.
- `extractHeaders()` lowercases every normalised name again
  (src/Http/Response/ResponseConverter.php:97) although
  `HeaderNameNormalizer` already computed `strtolower($name)` internally — a
  redundant `strtolower` per header per response. Suggested fix: have
  `normalize()` return/expose the lowercased key, or accept the trivial cost.
- Pre-existing phpunit warning: `XDEBUG_MODE=coverage` not set in the
  environment even though the phpunit config requests coverage — CI-level
  nit, affects local runs only.

## Verification

- `vendor/bin/phpunit --filter "ResponseConverter|ContentLengthDesync"` —
  OK (48 tests, 133 assertions).
- `phpstan analyse` on the three touched files — no errors.
- `php-cs-fixer fix --dry-run` on the three touched files — clean.
