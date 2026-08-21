# Code decision 1 — documentation-only fix for the max_input_vars cookie deviation (#628)

## Approach taken

The issue's own **minimum suggested fix**, nothing more:

1. `docs/security.md` — the "Known intentional deviations from `$_COOKIE`"
   paragraph gains a fourth deviation: PHP's SAPI stops registering cookies
   once `max_input_vars` (default 1000) is reached and drops the remaining
   pairs (logging an E_WARNING), while
   `RequestConverter::parseCookiesFromServerBag()` parses every pair in the
   header. A request carrying 1001+ pairs therefore sees all of its cookies
   under Workerman but only the first 1000 under PHP-FPM.
2. `src/DTO/RequestConverter.php` — docblock note on `parseCookiesFromServerBag()`
   pointing at the documented deviation. No behavior change.
3. `tests/RequestConverterTest.php` — characterisation test
   `testCookieParsingIsNotCappedAtMaxInputVars`: 1001 cookie pairs in one
   `Cookie` header → all 1001 registered, first and last values asserted.
   This is the gate that keeps docs and code in sync: if anyone ever adds a
   cap, the test fails and forces the security.md paragraph to change with it.
4. `CHANGELOG.md` — `[Unreleased]` → `Changed` entry referencing #628.

## What was rejected, and why

**The parity cap** (the issue's "if exact parity is ever wanted" option):
cap the cookie loop at a configurable limit defaulting to 1000.

- The issue explicitly defers it ("Minimum" vs "If exact parity is ever
  wanted") and is labelled `[Nit]` / `minor`.
- Capping is itself a **behavior change**: requests that today see all their
  cookies would silently lose everything past pair 1000. That needs its own
  decision (config surface? which limit? warn or drop silently?) and its own
  issue — not a rider on a documentation nit.
- Parity with FPM here is *dropping data*, i.e. strictly worse for any app
  that does not care about FPM byte-parity at absurd cookie counts.

## Anything uncertain

- The "(logging an E_WARNING)" claim in security.md paraphrases PHP's
  `php_default_treat_data()` overflow warning; the exact wording differs
  across PHP versions but an E_WARNING is emitted on all supported versions.
- Whether the deviation list order matters: appended last, after the
  whitespace micro-deviation, since it was discovered latest (#583/#627).
