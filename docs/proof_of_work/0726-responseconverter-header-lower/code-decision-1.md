# Code decision 1 — reuse the HeaderNameNormalizer cache key in extractHeaders() (#726)

## Approach taken

**By-ref out-parameter** on the existing single lookup method:

```php
public static function normalize(string $name, ?string &$lower = null): string
{
    $lower = strtolower($name);
    return self::$cache[$lower] ?? self::cacheMiss($lower, $name);
}
```

`extractHeaders()` now calls `normalizeHeaderName($name, $lowerName)` and uses
`$lowerName` directly for the `TRANSPORT_HEADERS` strip and the HEAD
`content-length` exception (src/Http/Response/ResponseConverter.php:96-99).
The redundant `strtolower($normalizedName)` is gone.

Why this shape:

- **Exactly one `strtolower` on the whole path** — the normalizer computes the
  cache key once and hands it out; the caller never recomputes.
- **Zero allocation on the hit path** — `$lower` is the very string already
  used as the array key; passing it by ref is a zval refcount bump, not a
  copy (COW). No array, tuple or value object is created per response.
- **The normalizer keeps owning the key invariant.** The out-param is
  *always* `strtolower($name)` — `normalize()` assigns it before any branch,
  including the implausibly-long-name early return in `cacheMiss()`. A caller
  cannot poison the cache key or the `CORRECTIONS` lookup.
- **No second public method.** The issue floated "a second lookup method
  reusing the same cache is likely enough"; the out-param on the existing
  method is smaller surface (one method, one `$lower` computation) and still
  matches the issue's own example ("`normalize(string $name, ?string &$lower
  = null): string`").
- Safety depends on the invariant `strtolower(normalize($name)) ===
  strtolower($name)`, which holds because `normalize()` only ever upper-cases
  (`ucfirst` per `-`-segment, plus the four `CORRECTIONS` entries, all of
  which lowercase back to their keys). Guarded by a new data-provider
  regression test.

The `ResponseConverter::normalizeHeaderName()` seam was kept (per the #574
findings note it is "trivially removable later") and extended with the same
out-param, so `extractHeaders()` stays readable and the by-ref plumbing is
isolated.

## What was rejected and why

1. **Returning `array{string, string}` (or a tuple/value object, or
   `normalizeWithLower(): array`)** — the issue explicitly forbids allocation
   on the hit path: "do NOT return arrays or allocate objects on the hot
   path". A per-header array on every response is exactly the regression the
   issue is about.
2. **Precomputed key passed in** (`normalize(string $name, ?string $lower =
   null)` where the caller computes `strtolower($name)` first and the
   normalizer reuses it) — also yields exactly one `strtolower` total, but
   inverts the invariant: the cache key becomes caller-controlled. A careless
   future caller could pass a non-lowercased key, silently breaking the
   `CORRECTIONS` lookup and caching a name under a wrong key that later
   callers hit. It also reads oddly (`normalize($name, strtolower($name))`)
   and forces every future caller to know about key-derivation rules. The
   normalizer should own key derivation and merely expose it.
3. **A second method `normalizeWithLower(string $name, ?string &$lower =
   null): string`** — functionally identical to the out-param but doubles the
   public surface and needs a private `lookup()` core to avoid duplicating
   logic, for a single caller. Rejected as needless API growth.
4. **Inlining `normalizeHeaderName()` into `extractHeaders()`** — dead-end
   cleanup, not required by the issue; the seam with the out-param keeps the
   diff minimal.

## Everything I was unsure about

- **PHPStan and the uninitialized by-ref variable.** Passing an undefined
  `$lowerName` by reference works in PHP (the callee creates the variable)
  and PHPStan did not complain about it, but it *did* flag
  `parameterByRef.unusedType` on `?string &$lower` because `normalize()`
  never assigns null. Fixed with the canonical `@param-out string $lower`
  docblock; the declared `?string &$lower = null` stays for callers that
  don't care about the key.
- **Implicitly-nullable trap.** I considered `string &$lower = null` (which
  would drop `?string`), but that is an implicitly-nullable type — deprecated
  since PHP 8.4 and this repo runs PHP 8.5 in CI. `@param-out` is the modern,
  deprecation-free way to narrow.
- **No allocation in PHP for by-ref string out.** String assignment is
  copy-on-write; `$lower = strtolower(...)` is a single zval, and the caller
  receiving it by ref does not duplicate the backing buffer. The only
  "cost" added to the hit path is a refcount bump — which the old code also
  paid for its own `$lowerName` variable, minus the whole `strtolower()`.
- **`composer test` (full E2E incl. daemon) passed** — 2147 tests / 16236
  assertions, OK (31 pre-existing skips). The change is also
  behavior-identical by construction: the reused key equals the previously
  recomputed `strtolower(normalize($name))` for every input, now proven by a
  regression test.
