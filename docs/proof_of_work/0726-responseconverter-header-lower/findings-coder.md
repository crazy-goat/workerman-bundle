# Findings — coder (issue #726)

## Obstacles / surprises

- **PHPStan `parameterByRef.unusedType`** (src/Http/Response/HeaderNameNormalizer.php:64):
  `?string &$lower` was rejected because `normalize()` never assigns null to
  the out-param. Fix: `@param-out string $lower` docblock — the canonical
  narrowing. Avoided the tempting `string &$lower = null` (implicitly-nullable
  type, deprecated since PHP 8.4; repo CI runs PHP 8.5).
- **Undefined-by-ref variable is fine for PHP and PHPStan.** `extractHeaders()`
  passes `$lowerName` before it is ever assigned; the callee creates it. No
  static-analysis complaint, no runtime issue.
- **`ContentLengthDesyncTest.php:195` reflection risk checked, not hit.** That
  test reflects only the `TRANSPORT_HEADERS` constant (guarding the strip
  list); no reflection touches `normalizeHeaderName()`, so the signature
  change is invisible to it. Ran it anyway — green.

## In scope, fixed

- Redundant `strtolower()` per header per response removed
  (src/Http/Response/ResponseConverter.php:97, previously `$lowerName =
  strtolower($normalizedName)`). The caller now reuses
  `HeaderNameNormalizer`'s internal cache key via the `$lower` out-param
  (src/Http/Response/HeaderNameNormalizer.php:67-69). Exactly one
  `strtolower` on the whole path, zero allocation on the hit path.

## Out of scope — noticed, not touched

- **`flattenHeaderValues()` allocates on every request regardless of this
  change** (src/Http/Response/ResponseConverter.php:116-126): `array_filter`
  + `array_values` + `count()` per header per response. Not an issue #726
  concern (and header *values* cannot reuse a static cache as safely as
  names), but if the hot path gets another perf pass, the single-value case
  could short-circuit before the two array copies, e.g. check
  `count($values) === 1 && $values[0] !== null` first.
- **`in_array($lowerName, self::TRANSPORT_HEADERS, true)` is a linear scan of
  a 3-element list.** Trivial, but it runs per header on every response; a
  `flip()`ed static map or `match` would be O(1). Not worth changing — 3
  elements, and this issue's measured cost was the `strtolower`.
- **The HEAD `content-length` exception now rides on the same reused key**
  (src/Http/Response/ResponseConverter.php:98). This is the one place where a
  future `CORRECTIONS` entry whose value lowercases differently from its key
  (e.g. `'dnt' => 'Do-Not-Track'`) would silently change wire behavior —
  covered by the new invariant test
  (`testNormalizedHeaderNameLowercasesBackToInput`).

## Verification

- `vendor/bin/phpunit tests/ResponseConverterTest.php tests/ContentLengthDesyncTest.php`
  — OK (52 tests, 132 assertions).
- `vendor/bin/phpunit tests/Strategy/DefaultResponseStrategyTest.php tests/HttpRequestHandlerTest.php tests/SymfonyControllerTest.php`
  — OK (107 tests, 10252 assertions).
- `composer lint` (php-cs-fixer, PHPStan level 8, Rector dry-run, kb-lint) — OK.
- **`composer test` (full suite incl. Workerman daemon on 8888/9999) ran clean** — 2147 tests, 16236 assertions, OK (31 skipped, pre-existing environment skips). The only gate not run: `test:coverage`/`coverage:check` (the phpunit warning in this environment is "No code coverage driver available"; the change only adds tests, so the 80 % floor cannot regress from it).
