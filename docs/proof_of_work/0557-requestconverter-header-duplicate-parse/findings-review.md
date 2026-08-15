# Findings — review (#557, round 1)

Appended by the review-critical subagent. One entry per finding. Status
"open" until triaged. Severities: high / medium / low / nit.

## Finding R1-1

- **file:line:** `tests/RequestConverterTest.php:1198` (new test block)
- **what is wrong:** No explicit parity test asserting that the fast path
  (gate returns false) produces a server bag byte-identical to the slow
  path (gate forced true) for the same unique-header input. The fast path
  is exercised only indirectly via `testHeadersAreAvailableInServerBag`
  (L412) and `testMiddlewareOverwrittenHeaderIsUsedWhenNoDuplicates`
  (L1327). A regression in the fast-path branch of the `foreach` (e.g. the
  `$rawHeaders[$nameLower] ?? null` lookup hitting when it should miss)
  would not be caught.
- **severity:** low
- **status:** open
- **automated check that could catch it:** a property/parity test
  (PHPUnit) converting the same buffer twice — once normally, once with
  the gate forcibly disabled via reflection — and `assertSame` on the full
  server bag.
- **relation to `findings-coder.md`:** not raised by the coder.

## Finding R1-2

- **file:line:** `src/Http/Request.php:44`
- **what is wrong:** `addedHeaderCount` is a performance-optimization
  detail of `RequestConverter` that has been pushed into the `Http\Request`
  DTO; the property docblock names `RequestConverter` as its sole consumer.
  This is a one-way read dependency (not a new cycle) and `Http\Request`
  already exposes `setHeader()`/`resetHeaders()`, but the coupling is real
  and worth noting for the retro. Less-coupled alternatives (boolean
  mutated flag, or dropping the counter and accepting the middleware
  false-positive rate) were rejected by the coder for good reasons
  (overwrite-only middleware would disable the optimization).
- **severity:** low
- **status:** open
- **relation to `findings-coder.md`:** not raised by the coder; the coder
  documented the design choice in `code-decision-1.md` "Alternatives
  considered" but did not flag the coupling itself.

## Finding R1-3

- **file:line:** `src/DTO/RequestConverter.php:322`
- **what is wrong:** `rawHeadMayHaveDuplicates()` calls `$rawRequest->rawHead()`
  for the CRLF count, and on the slow path `buildServerHeaders()` calls
  `parseRawHeaderLines($rawRequest->rawHead())` again. `rawHead()` is
  memoized (vendor `??=`), so the second call is a cheap array lookup, not
  a re-parse. Readability nit only.
- **severity:** nit
- **status:** open
- **relation to `findings-coder.md`:** not raised.

## Finding R1-4

- **file:line:** `src/DTO/RequestConverter.php:215` (pre-existing, referenced
  by `findings-coder.md` item 2)
- **what is wrong:** obs-fold / whitespace-prefixed header names survive as
  Workerman keys with a leading space and are forwarded to Symfony under a
  server key containing a literal space (e.g. `HTTP_ X_FOLD`), contrary to
  RFC 7230 §3.2.4. Real, pre-existing, not regressed by this PR (the gate
  forces a re-parse for these lines, so behavior is identical to before).
- **severity:** low
- **status:** open
- **relation to `findings-coder.md`:** agree with coder's "out-of-scope,
  file a separate issue" assessment.

## Finding R1-5

- **file:line:** `src/DTO/RequestConverter.php:359` vs vendor
  `Request.php:518` (referenced by `findings-coder.md` item 3)
- **what is wrong:** value trim sets differ: `parseRawHeaderLines()` uses
  `ltrim($value)` (default mask `" \t\n\r\0\x0B"`) while Workerman uses
  `trim($parts[1], " \t")`. Only observable on duplicate headers (raw
  values joined), and any byte in the divergence set is rejected by the
  control-character check at L204. Cosmetic.
- **severity:** nit
- **status:** open
- **relation to `findings-coder.md`:** agree with coder's "cosmetic, align
  if the parser is ever touched again".

## Finding R1-6

- **file:line:** `vendor/workerman/workerman/src/Protocols/Http/Request.php:439`
  (referenced by `findings-coder.md` item 1)
- **what is wrong:** `rawHead()` can return `false` from `strstr()` when
  the buffer has no `"\r\n\r\n"`, violating its `: string` return type.
  Latent (Workerman's connection layer buffers the full head before
  `Http::decode()`), and not a regression (the pre-gate code had the same
  exposure). Vendor fix would be `?: ''`.
- **severity:** low
- **status:** open
- **relation to `findings-coder.md`:** agree with coder's "latent vendor
  bug, not actionable here".
