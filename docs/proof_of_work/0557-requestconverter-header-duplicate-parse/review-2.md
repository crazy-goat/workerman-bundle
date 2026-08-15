# Review — round 2 (#557)

Branch: `perf/issue-557-requestconverter-re-parses-the-raw-heade`
Round-2 commits: `ea17fc0` (parity test + findings-review.md status update).
Round-2 diff scope: `git diff d3df459..ea17fc0` — only
`tests/RequestConverterTest.php` (+37) and
`docs/proof_of_work/0557-requestconverter-header-duplicate-parse/findings-review.md`
(1-line status change on R1-1). No production code changed in round 2
(`git diff d3df459..ea17fc0 -- src/` is empty).

## KB entries read (tag-index only)

- FAQ-012 (tests,http,headers) — underscore-header fixtures need a literal
  `_`. Not directly applicable to the parity test (it uses no underscore
  headers), but consistent with it.
- DEC-004 (static-files,memory) — not applicable (no StaticFilesMiddleware
  change).
- DEC-005 (http,memory,security) — trusted-host cache; not applicable.
- DEC-010 (http,cookies,security) — cookie parsing via `rawurldecode()` after
  split. The parity test asserts `HTTP_COOKIE` parity; the cookie *values* are
  not decoded inside `buildServerHeaders` (decoding happens later in
  `parseCookiesFromServerBag`), so `HTTP_COOKIE` parity is sufficient for the
  server-bag comparison. No violation.

No KB entries were written or appended by this subagent (read-only per
instructions). No `docs/helpers/` violations to flag.

## Per-finding status (R1-1 .. R1-6)

| Finding | Severity | Status | One-line evidence |
|---------|----------|--------|-------------------|
| R1-1 | low | **fixed (round 2)** | `tests/RequestConverterTest.php:1342` `testFastPathAndSlowPathProduceIdenticalServerBagForUniqueHeaders` flips the gate (`assertFalse` fast / `assertTrue` slow, L1358-1359), asserts `assertSame` on 7 server keys incl. CGI-special `CONTENT_TYPE`/`CONTENT_LENGTH` (L1364-1376). Gate flip verified empirically: fast `rawHead` CRLF=7 vs header count=7 → false; slow CRLF=8 vs 7 → true. Runs and passes (9 assertions). |
| R1-2 | low | still present (unchanged) | `src/Http/Request.php:44` `addedHeaderCount` docblock still names `RequestConverter` as consumer; `git diff d3df459..ea17fc0 -- src/Http/Request.php` empty. Correctly classified low, open. |
| R1-3 | nit | still present (unchanged) | `src/DTO/RequestConverter.php:322` second `rawHead()` call still relies on vendor memoization; production unchanged. Correctly classified nit, open. |
| R1-4 | low | still present (unchanged) | `src/DTO/RequestConverter.php:215` obs-fold/leading-space keys still forwarded; production unchanged, pre-existing, not regressed. Correctly classified low, open. |
| R1-5 | nit | still present (unchanged) | `src/DTO/RequestConverter.php:359` `ltrim($value)` vs vendor `trim($parts[1], " \t")` divergence still present; production unchanged. Correctly classified nit, open. |
| R1-6 | low | still present (unchanged) | vendor `Request.php:439` `rawHead()` `: string` vs `strstr()` returning `false`; latent, not actionable here. Correctly classified low, open. |

### R1-1 detailed verification

The new test at `tests/RequestConverterTest.php:1342-1377` was checked
against the three required properties:

(a) **Flips the gate** — L1358 asserts `assertFalse` on the fast buffer,
L1359 asserts `assertTrue` on the slow buffer, both via
`callPrivateStaticMethod('rawHeadMayHaveDuplicates', ...)`. Empirically
confirmed: fast `rawHead()` has 7 CRLFs and 7 parsed headers →
`7 !== 7 - 0` is false → gate false. Slow `rawHead()` has 8 CRLFs (the
extra garbage line adds one) and still 7 parsed headers (garbage has no
colon) → `8 !== 7 - 0` is true → gate true. **Pass.**

(b) **`assertSame` on relevant server keys across both paths** — L1370-1376
loops `assertSame($fastServer[$key] ?? null, $slowServer[$key] ?? null, ...)`
over `HTTP_HOST`, `HTTP_ACCEPT`, `HTTP_AUTHORIZATION`, `CONTENT_TYPE`,
`CONTENT_LENGTH`, `HTTP_X_CUSTOM`, `HTTP_COOKIE`. **Pass.**

(c) **"Slow" buffer differs from "fast" ONLY by a colon-less garbage line**
— L1353 appends `"garbage-line-without-colon\r\n\r\n"` (vs fast's `"\r\n"`).
Verified `str_contains("garbage-line-without-colon", ":")` is **false**, so
`parseRawHeaderLines` (L356 `str_contains($line, ':')`) skips it and
Workerman's `header()` does not register it — parsed headers are identical
on both paths. The extra line's sole effect is to inflate the CRLF count by
one, which is exactly what flips the gate to the slow path without altering
the semantic input. **Pass.**

All three properties hold. R1-1 is fixed.

### R1-2 .. R1-6 confirmation

Round 2 made no production change (`git diff d3df459..ea17fc0 -- src/` empty),
so R1-2 through R1-6 are structurally unchanged. Their severities and
"open" status remain correct: R1-2 (low, real but accepted coupling),
R1-3 (nit, memoized second call), R1-4 (low, pre-existing obs-fold, agreed
out-of-scope), R1-5 (nit, cosmetic trim-mask divergence), R1-6 (low, latent
vendor `: string` lie). No disagreement with the round-1 classification.

## Round-1 core correctness claim — re-confirm

The gate at `src/DTO/RequestConverter.php:317-336` is unchanged. Re-read
confirms the claim: `rawHeadMayHaveDuplicates()` returns `true` (slow path)
whenever (i) the CRLF count of `rawHead()` differs from
`count($workermanHeaders) - addedHeaderCount`, or (ii) any Workerman header
name is not equal to its `trim()`. It returns `false` only when the counts
match AND every name is already trimmed — a state in which no colon-less
line was skipped and no name occurred twice (two same-named lines would
collapse to one Workerman key but two CRLFs). Therefore the gate can
**false-positive** (e.g. colon-less garbage inflates CRLF count with no
actual duplicate) but never **false-negative** (a real duplicate or a
skipped colon-less line always forces the slow re-parse). Claim still holds.
No production line changed since round 1.

## New findings (round 2)

| ID | file:line | description | severity | status |
|----|-----------|-------------|----------|--------|
| — | — | none | — | — |

No new issues introduced by the parity test or missed in round 1.

Specific concerns raised in the round-2 brief, each checked and dismissed:

- **Does the parity test actually run both paths, or could `rawHead()`
  memoization / `header()` caching make "slow" take the fast path?**
  Dismissed. `$fastRequest` and `$slowRequest` are two distinct
  `new Request($buffer)` instances (L1355-1356). Workerman's `rawHead()`
  memoization is per-instance (`$this->_rawHead ??=`), so the slow instance
  recomputes its own head from its own buffer. The gate is invoked with each
  instance separately (L1358-1359) and `toSymfonyRequest` is called on each
  separately (L1361-1362). No cross-instance leakage is possible.

- **Is the colon-less garbage line really colon-less?**
  Yes. `str_contains("garbage-line-without-colon", ":")` returns false
  (verified empirically). A stray `:` would make `parseRawHeaderLines` treat
  it as a header (L356-360) and Workerman would register it, breaking both
  the gate flip and parity. The chosen literal is safe.

- **Does the test cover the CGI-special headers (`CONTENT_TYPE`,
  `CONTENT_LENGTH`) on the right (post-move) keys?**
  Yes. `buildServerHeaders` moves `HTTP_CONTENT_TYPE`→`CONTENT_TYPE` and
  `HTTP_CONTENT_LENGTH`→`CONTENT_LENGTH` (L237-243) and unsets the `HTTP_`
  forms. The test asserts on `CONTENT_TYPE` and `CONTENT_LENGTH` (L1366),
  not on `HTTP_CONTENT_TYPE`/`HTTP_CONTENT_LENGTH`. Correct.

- **PHPStan / php-cs-fixer / rector concerns with the new test?**
  None. `composer lint` (PHP CS Fixer + PHPStan + Rector + kb-lint) is green
  on all 245 files including the modified test (see Gates below). The test
  uses the existing `callPrivateStaticMethod` helper (L1532) and
  `RequestConverter::toSymfonyRequest` public API; no new symbols introduced.

## Gates

| Gate | Command | Result |
|------|---------|--------|
| Lint | `composer lint` | **PASS** — PHP CS Fixer 0/245 fixable; PHPStan [OK] No errors; Rector [OK] done; kb-lint OK (0 warnings, 0 stale). (Note: PHP CS Fixer warns it is running on PHP 8.5.9 while `composer.json` min is 8.2; this is a pre-existing environment note, not a failure and not introduced by this round.) |
| Unit (targeted) | `vendor/bin/phpunit tests/RequestConverterTest.php` | **PASS** — 90 tests, 316 assertions, 0 failures. The only PHPUnit warning is "No code coverage driver available" (environment, not a test failure). The `[warning] Dropped HTTP header ...` lines are expected stderr from existing underscore-header tests. |
| Unit (parity test only) | `vendor/bin/phpunit --filter testFastPathAndSlowPathProduceIdenticalServerBagForUniqueHeaders` | **PASS** — 1 test, 9 assertions (2 gate + 7 key). |

Full `composer test` was not run (it boots a Workerman daemon on ports
8888/9999); the targeted RequestConverter suite is sufficient because only
that test file changed in round 2 and no production code changed.

## Merge recommendation

**GO.** Round 2 closes the only fix-before-merge finding (R1-1) with a
correct, complete, and gate-flipping parity test; the five remaining
low/nit findings (R1-2..R1-6) are unchanged, correctly classified, and
deliberately deferred; all gates are green and no new issues were
introduced.
