# Review round 1 — #741

## Prior findings

No prior unresolved findings; this is the initial review.

## Review

`flattenHeaderValues()` now checks the Set-Cookie exception and the exact one-element/non-null shape before allocating filtered copies. It returns the original string value for that shape. Empty arrays, null-only arrays, arrays containing null, multiple ordinary values, and all Set-Cookie inputs still pass through the existing `array_filter()`/`array_values()` implementation. This preserves the observable output shape and avoids a behavior change outside the requested hot path.

## Checks

- `tests/ResponseConverterTest.php` — passed (43 tests, 97 assertions).
- `composer lint` — passed (existing knowledge-base budget warnings only).
- Changelog check and `git diff --check` — passed.

## Findings

None. No knowledge-base candidate is needed.
