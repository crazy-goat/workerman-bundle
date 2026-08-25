# #718 — Review Round 1

Reviewer role: review-critical (RFC 7230 §3.2.4 header-name hardening). Branch
`fix/issue-718-obs-fold-whitespace-prefixed-header-name`, diff
`origin/master...HEAD`. Scope: `src/DTO/RequestConverter.php`
(`buildServerHeaders()`) and `tests/RequestConverterTest.php`.

## What I checked

- Read the full diff and the surrounding `buildServerHeaders()` body
  (lines ~178-229), `rawHeadMayHaveDuplicates()` (line ~313) and
  `parseRawHeaderLines()` (line ~347) to confirm ordering and the slow-path
  join.
- Read KB entries FAQ-012, FAQ-025, FAQ-027 (tagged `http`, `headers`,
  `security`, `php-strings`, `tests`).
- Ran `vendor/bin/phpstan analyse src/DTO/RequestConverter.php --no-progress`
  -> OK, no errors (level 8).
- Ran `vendor/bin/phpunit --filter RequestConverterTest` -> 98 tests, 552
  assertions, all pass (only the pre-existing "no coverage driver" runner
  warning).
- Empirically exercised Workerman's `header()`/`rawHead()` and the real
  `RequestConverter::toSymfonyRequest()` on crafted buffers to confirm the
  drop, the join, and edge cases (leading/trailing/both-whitespace, lone
  whitespace name, and the internal-space case).

## What is good

- Drop sits before `$key` construction. The new
  `if ($name !== \trim($name)) { continue; }` (lines 227-230) is placed
  after the underscore-drop block and before `$key = 'HTTP_' . ...`
  (line ~232). The malformed name is dropped before any `HTTP_ X_FOLD`-style
  key is ever built. Correct.
- Space-key leakage is fixed for the targeted cases. A leading-space obs-fold
  name (`" x-fold"`) and a trailing-space name (`"x-lead "`) are both dropped;
  neither `HTTP_ X_FOLD` nor `HTTP_X_TRAIL ` (nor a silently-trimmed
  `HTTP_X_TRAIL`) is present. Verified.
- Legitimate duplicate-join preserved. With `X-Fold: a` + ` X-Fold: b`, the
  well-formed `x-fold` is joined on the slow path to `a, b` under
  `HTTP_X_FOLD`, and the malformed ` x-fold` is dropped. The new test
  `testObsFoldWhitespacePrefixedHeaderNameIsNotForwardedUnderSpaceKey` asserts
  BOTH the absence of `HTTP_ X_FOLD` and the joined value. This exercises the
  drop rather than sailing past it (FAQ-012 compliant).
- PSR-12 / style. Comment is accurate and matches surrounding indentation.
  PHPStan level 8 clean on the file.

## What is wrong

### F-1 (medium) — internal-space header name is NOT dropped and still leaks a space-key
`src/DTO/RequestConverter.php:227`. The chosen mechanism is
`$name !== \trim($name)`. `trim()` only removes leading/trailing whitespace,
so a name with an internal space — e.g. `X-Fold Bar` — is unchanged by
`trim()` and passes the guard. Workerman keeps it as the key `"x-fold bar"`
(verified: `parseHeaders()` lower-cases verbatim, no trim). The converter then
builds `HTTP_X_FOLD BAR` — a `$_SERVER` key containing a literal space, which
is the exact same smuggling-adjacent defect class #718 set out to close, just
triggered by a different input.

Empirical proof (real converter, buffer `X-Fold Bar: v`):
```
Keys containing a space:
  'HTTP_X_FOLD BAR' => 'v'
```

This contradicts the issue's stated intent ("and other whitespace-padded
names must not be forwarded") and the code-decision's claim that the check
covers the relevant space cases. RFC 7230 §3.2.4 is narrowly about OWS around
the name/colon (leading/trailing), so internal-space is arguably a separate
field-name grammar (token) violation — but (a) the resulting `$_SERVER`
space-key leak is identical, and (b) the same `name !== trim(name)` line is the
natural place to close it. No automated check catches this; recommend either
extending the drop with a token/`\strpbrk($name, " \t")` check or, if
deliberately deferred, opening a follow-up issue and stating so in the
code-decision. A new fixture such as `testInternalSpaceHeaderNameIsDropped`
would guard it.

### F-2 (low) — findings-coder.md makes a false claim about internal spaces
`docs/proof_of_work/0718-obs-fold-whitespace-prefixed-header-name/findings-coder.md`,
the out-of-scope bullet on `parseRawHeaderLines()`. It states internal-space
names are "already rejected by Workerman's tokenisation in practice". This is
false — Workerman retains `"x-fold bar"` (confirmed in F-1) and the bundle
forwards it as a space-key. The note must be corrected; at minimum it should
say the internal-space leak is real and either handled (after F-1 fix) or
explicitly deferred.

### F-3 (nit) — buildServerHeaders() docblock omits the new drop
`src/DTO/RequestConverter.php:178-192`. The docblock enumerates the underscore
drop ("Header names containing underscores are discarded …") but does not
mention that whitespace-padded (OWS/obs-fold) names are now silently dropped.
For symmetry and to document the new contract, add a bullet.

### F-4 (low / nit) — pre-existing join test asserts only the value, not the absence of the space-key
`tests/RequestConverterTest.php:1332`
(`testWhitespacePrefixedDuplicateHeaderIsStillJoined`). It asserts
`HTTP_X_FOLD => 'a, b'` but NOT `assertArrayNotHasKey('HTTP_ X_FOLD', …)`, so
the latent bug would have sailed past it (exactly why #718 went unnoticed).
The coder's own findings note suggests back-filling this assertion but did not.
The new `testObsFold…` test already covers the combined case, so this is
belt-and-braces, not blocking.

## Verdict
Has findings. The core fix (OWS-padded names) is correct, well-placed, and
passes PHPStan + 98 tests. However F-1 is a genuine same-class leak that the
fix's own mechanism does not cover, and F-2 is a documentation inaccuracy that
must be corrected. F-3/F-4 are non-blocking polish.
