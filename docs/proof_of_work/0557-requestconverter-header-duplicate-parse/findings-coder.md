# Findings — coder (#557)

## Obstacles / surprises

- **The issue's suggested formula has the wrong offset for this Workerman
  version.** `substr_count($rawHead, "\r\n") - 1 !== count($headers)` assumes
  `rawHead()` keeps the trailing CRLF. The vendored Workerman
  (`vendor/workerman/workerman/src/Protocols/Http/Request.php:437-440`)
  returns `strstr($buffer, "\r\n\r\n", true)`, which excludes it, so the
  correct offset is 0. Verified empirically before writing any code.
- **The count check alone is NOT sound — two genuine false negatives were
  found while designing it:**
  1. *Name-trim divergence*: `parseRawHeaderLines()` trims header names,
     Workerman's `parseHeaders()` does not
     (`vendor/.../Http/Request.php:517`). `"X-Fold: a\r\n X-Fold: b\r\n"` is a
     duplicate for the raw parser but two distinct keys for Workerman, so the
     counts match while a duplicate exists. Fixed by the
     `$name !== trim($name)` override.
  2. *Middleware-added headers*: middleware runs before
     `SymfonyController` converts the request, so `header()` may contain names
     absent from the raw head. An attacker could send exactly as many extra
     duplicate lines as the middleware adds new names, making the counts match
     and hiding a duplicate `Cookie` (the #217 class). Fixed by tracking
     `addedHeaderCount()` on `Http\Request` and subtracting it.
- **Workerman joins duplicate headers with `,` (no space)** in `header()`
  (`vendor/.../Http/Request.php:519-523`) — the issue/task brief said "returns
  the LAST value for duplicate headers", which is not what this version does.
  It does not affect the fix (only `count()` is used), but the brief's
  premise was inaccurate.

## Benchmark numbers (PHP 8.5.9, `phpbench --report=aggregate`, 1000 revs × 5 its)

| subject | before | after | delta |
| --- | --- | --- | --- |
| benchSimpleRequest | 4.428µs ±1.91% | 4.224µs ±1.64% | −4.6% |
| benchHeaderHeavyRequest | 9.812µs ±2.74% | 8.119µs ±0.70% | **−17.3%** |
| benchMultipartRequest | 9.134µs ±1.68% | 8.675µs ±0.82% | −5.0% |
| benchResetHeaders | 0.189µs ±1.12% | 0.197µs ±2.86% | +4% (noise) |

(A first after-run showed 8.233µs ±4.53% for the header-heavy subject; the
numbers above are the confirming second run.)

## Bugs / weak spots noticed (outside #557 scope)

1. **`vendor/workerman/workerman/src/Protocols/Http/Request.php:437-440`** —
   `rawHead()` can return `false` (from `strstr`) when the buffer contains no
   `"\r\n\r\n"`, violating its `: string` return type with a `TypeError`.
   Workerman's `Http::decode()` guarantees the terminator in practice, so this
   is latent. Vendor code; fix would be `?: ''` upstream. Not actionable here.
2. **`src/DTO/RequestConverter.php:213`** (pre-existing, preserved by this
   change) — a whitespace-prefixed header line (obs-fold style) survives as a
   Workerman key with a leading space and is forwarded to Symfony under a
   server key containing a literal space (e.g. `HTTP_ X_FOLD`). RFC 7230
   says such lines should be rejected or folded. Suggested fix (separate
   issue): drop headers whose raw name has leading/trailing whitespace instead
   of forwarding them. Deliberately NOT changed here — behavior must stay
   identical in this PR.
3. **`src/DTO/RequestConverter.php:318` vs Workerman
   `vendor/.../Http/Request.php:518`** — value trim sets differ:
   `parseRawHeaderLines()` uses `ltrim()` defaults (` \t\n\r\0\x0B`) while
   Workerman uses `trim($parts[1], " \t")`. Only observable on duplicate
   headers (raw values are used for the join), and any byte in the difference
   set is rejected by the control-character check anyway, so it is cosmetic.
   Worth aligning if the parser is ever touched again.
4. **Nothing else found outside scope.** The cookie path
   (`parseCookiesFromServerBag`) and the control-character validation were
   read in full and left untouched per DEC-010 / #217.

## Verification

- `vendor/bin/phpunit --no-coverage tests/RequestConverterTest.php` — 89 tests,
  307 assertions, OK (includes 10 new tests for the gate: fast path, each
  false-positive class, middleware compensation/overwrite, and behavioral
  parity).
- `composer test` — 2046 tests, 15652 assertions, OK (31 skipped,
  environment-dependent; none related to this change).
- `composer lint` — php-cs-fixer, PHPStan level 8, rector, kb-lint: all clean.
