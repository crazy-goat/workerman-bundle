# Review round 1 — #630 control-character filter: `preg_match` → `strcspn`

Branch: `perf/issue-630-requestconverter-control-character-filte`
Base: `master` · 1 commit (`716dc85`)
Reviewer: review-critical (round 1) · read-only

## Earlier findings

`docs/proof_of_work/0630-requestconverter-control-character-filter/findings-review.md`
did **not exist** before this round (round 1). Nothing to revisit — went straight
to hunting. `findings-coder.md` exists but is the coder's obstacle log, not review
findings; its out-of-scope note (URI `validateUri` rejects TAB while header values
accept it) describes **pre-existing** behaviour untouched by this diff and is not a
finding for this change.

## Summary verdict

**APPROVE.** The swap is a byte-identical, behaviour-preserving replacement of a
per-header `preg_match` character-class check with `strcspn` against an explicit
32-byte mask. Equivalence was verified exhaustively for all 256 byte values in
middle, leading, and trailing position, plus the empty string and long strings —
zero mismatches against the old regex in accept/reject semantics. The
`MalformedRequestException` message and its `addcslashes` escaping are untouched.
The regression test was hardened from a 0x00–0x1F+0x7F sweep to a full 256-value
table and genuinely exercises the real `toSymfonyRequest()` conversion path (no
Workerman-parser early bailout can false-pass it). PHPStan level 8, php-cs-fixer,
and the full `RequestConverterTest` suite (90 tests / 539 assertions) are clean.
No documented security decision (DEC-006 hardening, DEC-013 fail-open gate policy)
is loosened — this is not a gate, it is the filter itself, still run on every
header value. The only actionable finding is a nit: the 32-byte mask is duplicated
between production and the benchmark with no link pinning them together.

## Knowledge-base compliance

Loaded the tag indexes for `docs/helpers/faq.md` and `docs/helpers/decisions.md`
and read only entries whose tags match the changed files (headers, security,
http, benchmarks, tests, performance):

- **DEC-006** (security hardening from #582–#586 must stay intact) — the
  control-character rejection is part of this hardening. The swap preserves the
  exact reject set `{0–8, 10–31, 127}` and the exact accept set (TAB + everything
  else incl. 0x80–0xFF obs-text), verified exhaustively. **Not violated.**
- **DEC-013** (optimization gates on security-relevant parsers must fail open /
  re-parse on every unverified input shape) — this change is **not** a gate. It is
  a 1:1 replacement of the filter predicate that still runs on every header value
  in the `foreach ($workermanHeaders …)` loop. There is no conditional bypass that
  could skip the slow path. The actual optimization gate
  (`rawHeadMayHaveDuplicates`, #557) is untouched by this diff. **Not violated.**
- **DEC-010** (cookie rawurldecode semantics, `trigger="parsing cookies or
  changing RequestConverter"`) — the cookie path is not touched by this diff.
  **Not affected.**
- **FAQ-025** (Workerman `parseHeaders()` semantics: no name trim, joins
  duplicates with bare `,`, `rawHead()` excludes trailing CRLF) — re-read to
  validate the 256-value test (see "Test validity" below). Consistent.
- **FAQ-026** (`phpbench` report name is `aggregate`, not `average`) — the added
  benchmark methods and `benchmarks/RequestConverterBench.md` use `aggregate`
  (the `composer bench` script also uses `--report=aggregate`). **Compliant.**

No `docs/helpers/` entries were modified by this change (the diff does not touch
`docs/helpers/`); the pre-existing `kb-lint` warning about `faq.md` exceeding the
300-line budget is unrelated to this PR.

## Core equivalence verification (the crux of this review)

**Claim under challenge:** `strcspn($value, MASK) < strlen($value)` is
byte-identical to `preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1` for
all 256 byte values, TAB, obs-text, and the empty string — both directions
(reject set and accept set).

**Mask inspection** (`src/DTO/RequestConverter.php:29–30`):
`HEADER_VALUE_CONTROL_CHARS` = bytes `0x00–0x08` (9) + `0x0A–0x1F` (22) + `0x7F`
(1) = **32 bytes**. Programmatically confirmed: the mask has exactly 32 distinct
bytes, **0 missing** vs the regex reject set, **0 extra**, and contains **no**
`0x2E` (`.`) or `0x2D` (`-`) — so even if a mask consumer interpreted ranges it
could not form one. (Independent confirmation: `strcspn`/`strpbrk` treat the mask
as a **literal character set and do NOT support `..` ranges** — verified:
`strcspn('M','A..Z') === 1`, i.e. `M` is not matched; only `addcslashes`/`rtrim`-
style charlists support `..`. The code comment "strcspn() takes a literal mask,
not a range" is therefore **accurate**, and the explicit byte-by-byte form is the
safe choice — a future "shorthand" like `"\x00..\x08"` would silently match only
`{0x00, '.', 0x08}` and let bytes 1–7 through: a security regression. Good that
the author avoided it.)

**Exhaustive runtime sweep** (PHP 8.5.9, the repo's target engine):
- All 256 single-byte values: `oldRej !== newRej` → **0 mismatches**.
- Empty string: `preg_match → false`, `strcspn < strlen → 0 < 0 → false`. **Match**
  (both accept).
- TAB `0x09`: both accept. `0x80`–`0xFF`: both accept (obs-text).
- Two-byte combos (every bad byte × every good byte, both orders): **0 mismatches**.
- Long accepted (500×'A'), long rejected (500×'A' + `0x7F` at end): **0 mismatches**.
- Byte in **middle** (`before{b}after`, the test's shape), **leading**, and
  **trailing** position, all 256 values: **0 mismatches**.

**`<` vs `!==` edge:** `strcspn` returns the length of the initial mask-free
segment, which is always `≤ strlen($value)`. Therefore `strcspn < strlen` and
`strcspn !== strlen` are equivalent for this predicate; `<` is correct and never
inverts on any input. `strlen` is `O(1)` (reads `zend_string.len`), called once
per header value alongside `strcspn` — no hot-path cost concern.

**Multibyte:** `strcspn` and `strlen` are both byte-based and locale-independent;
UTF-8 continuation/lead bytes (0x80–0xFF) are not in the mask, so multibyte
sequences pass through byte-wise exactly as under the regex. No `mbstring`
overload hazard (removed in PHP 8).

**Exception path:** `addcslashes($stringValue, "\x00..\x1F\x7F")` (line 214) is
unchanged. `addcslashes` *does* support `..` ranges, so `"\x00..\x1F"` is a
0x00–0x1F range plus literal `0x7F` — correct and byte-identical to before. The
`MalformedRequestException` message string is therefore unchanged.

**Conclusion:** the swap is provably behaviour-preserving. The security property
(reject exactly `{0–8, 10–31, 127}` in header values; accept TAB and obs-text) is
intact — neither weakened nor strengthened.

## Test validity — could the 256-value table false-pass?

The test (`tests/RequestConverterTest.php:320–357`) builds
`"GET /control-byte HTTP/1.1\r\nHost: x\r\nX-A: before{byte}after\r\n\r\n"`,
constructs `new Request($buffer)`, and calls `RequestConverter::toSymfonyRequest()`.
Concern: does Workerman's `parseHeaders()` mangle or drop the byte before the
bundle's filter runs, allowing a false pass?

Traced `vendor/workerman/workerman/src/Protocols/Http/Request.php:493–525`:
`parseHeaders()` does `explode(':', $content, 2)` then `trim($parts[1], " \t")`.
The byte sits in the **middle** of the value (`before{byte}after`), so `trim`
(leading/trailing space+TAB only) never touches it. The colon is at a fixed
position before the byte, so `explode(':', …, 2)` always yields `parts[1]` — the
header is never dropped to the colon-less `continue` branch. The value (with the
raw byte) lands in `$this->data['headers']['x-a']` and reaches
`buildServerHeaders()`.

Edge bytes that could disturb the framing:
- `0x0A` (LF): `rawHead() = strstr($buf, "\r\n\r\n", true)` finds the terminator
  only at the end (lone `\n` inside the value is not `\r\n`); `explode("\r\n", …)`
  keeps `X-A: before\nafter` as one element. LF survives to the filter. ✅
- `0x0D` (CR): `before\rafter` — the CR is followed by `a`, not `\n`, so no false
  `\r\n` boundary is formed inside the value; `explode("\r\n", …)` keeps it whole.
  CR survives to the filter. ✅
- `0x00` (NUL): PHP string ops are binary-safe; NUL survives `explode`/`trim`/map
  storage. ✅

For **rejected** bytes, if the header were somehow dropped, the test calls
`$this->fail('Expected byte 0x%02X to be rejected')` — a false pass would surface
as a **test failure**, not a silent green. For **accepted** bytes, the test
asserts `assertSame($value, $server->get('HTTP_X_A'))`, which would fail if
Workerman altered the byte. The suite reports **288 assertions** (224 accepted × 1
+ 32 rejected × 2 = 288), confirming every one of the 256 bytes actually ran
through `toSymfonyRequest()`. **The test is sound and cannot false-pass.**

The production mask is transitively pinned by this 256-value behaviour test: adding
an extra byte to the mask (e.g. accidentally including `0x20`) would reject space
and break the accepted-path assertion; dropping a byte (e.g. `0x0A`) would accept
LF and break the rejected-path assertion. Good guard against mask drift.

## Benchmark validity

`benchmarks/RequestConverterBench.php` adds three param-provider-driven subjects
(`benchFilterRegex` / `Strpbrk` / `Strcspn`) over seven representative values
(short/long, accepted/rejected, ASCII/UTF-8, bad-byte-first/last). Reproduced
locally (PHP 8.5.9, opcache off, xdebug off, 1000 revs × 3 its): strcspn is faster
than the JIT-compiled regex on every non-trivial row (`utf8Accepted` 0.207 vs
0.256 µs ≈ −19%; `longRejectedLate` 0.284 vs 0.361 µs ≈ −21%) and within noise on
sub-0.1 µs rows. `longAccepted` on this run was 0.532 vs 0.558 µs (~−5%, rstdev
±8.5%) — narrower than the report's 0.455 vs 0.576 but still not slower; the
report's "10–28% faster on every non-trivial value" is slightly optimistic for
this single row but the directional conclusion (strcspn never slower, usually
faster) holds. `strpbrk` was correctly rejected: it materialises the remainder
substring and its long-value numbers swing between runs.

Environment honesty: the report states opcache off, xdebug off, PCRE2 JIT **on** —
making the strcspn win the conservative case (JIT-less regex would be slower
still). `benchFilterRegex` uses the **original** regex pattern
`'/[\x00-\x08\x0A-\x1F\x7F]/'`, so the comparison is genuinely old-vs-new. The
whole-request table honestly records results within run-to-run noise
(`benchSimpleRequest` +2.1% labelled "noise"). The benchmark is fit for purpose.

One accuracy nit in the markdown prose: the report claims "rstdev ≤ 7% on rows
above 0.1 µs", but the reproduced run showed ±8.91% and ±12.35% on two regex rows.
This is inherent micro-benchmark variance, not a code defect, and does not affect
the conclusion. Not raised as a separate finding.

## Findings

### F-1 — nit — duplicated 32-byte mask between production and benchmark

**`src/DTO/RequestConverter.php:29` (`HEADER_VALUE_CONTROL_CHARS`) and
`benchmarks/RequestConverterBench.php:29` (`CONTROL_CHAR_MASK`).**

The two constants are textually identical 32-byte masks, maintained independently.
The benchmark's stated purpose is to compare the **old** regex against the **new**
strcspn; for that comparison to be meaningful the benchmark mask must equal the
production mask. Today they match, but there is no link pinning them: if a future
change alters the production reject set, the benchmark would silently test a stale
mask and its old-vs-new verdict would compare apples to oranges — undermining the
evidence basis this PR relies on. The production mask is transitively pinned by
the 256-value behaviour test, but the benchmark mask is pinned by nothing.

**Impact:** low — benchmark only, no production/security effect; the divergence
is hypothetical future drift, not a current defect.

**Smallest safe fix direction:** either (a) add a one-line comment on
`CONTROL_CHAR_MASK` cross-referencing `RequestConverter::HEADER_VALUE_CONTROL_CHARS`
with "keep in sync", or (b) add a small unit test that reads both constants
(production one is `private`, so via `ReflectionClass::getConstant`) and asserts
byte-equality, so drift fails CI. (a) is the minimal change; (b) makes it
enforceable.

**Automated check that could have caught it:** none today. A reflection-based
constant-equality test (option b) would catch future drift; no static-analysis
rule flags two identical-looking string literals diverging.

## Remaining risk areas checked clean

- **Byte-identical behaviour** — verified exhaustively (see above); no bypass, no
  off-by-one, empty-string and obs-text handled correctly.
- **Mask composition** — 32 bytes, exact reject set, no range-interpretable bytes;
  `strcspn` mask-is-literal fact confirmed (comment is accurate).
- **`<` vs `!==`, `strlen` cost, multibyte** — all clean.
- **Exception message / `addcspathes` escaping** — unchanged, byte-identical.
- **Test false-pass path** — traced through Workerman `parseHeaders()`; the test
  genuinely exercises the real conversion path for all 256 bytes; a dropped header
  would fail the test, not silently pass.
- **Security policy (DEC-006, DEC-013)** — not loosened; the filter still runs on
  every header value; no gate/fail-open surface introduced.
- **BC** — the new constant is `private`; no public interface changed; no parameter
  added to any published method. No BC break.
- **Long-lived-worker hazards** — class constant (immutable), no per-request state,
  no cache, no closure, no timer. None.
- **PHPStan level 8 / php-cs-fixer / `bin/kb-lint.php`** — all clean on the
  changed files (`kb-lint`'s `faq.md` over-budget warning is pre-existing and
  unrelated).
- **`RequestConverterTest` full suite** — 90 tests / 539 assertions, all pass.
- **Coverage floor** — `composer.json` `coverage:check` still `80.0`; unchanged.
- **Docs** — `docs/security.md:75` documents the reject set by behaviour (not
  mechanism), so it remains accurate; README/UPGRADE do not mention the filter
  mechanism; CHANGELOG entry is under `Unreleased > Performance` with the issue
  link, matching repo convention.
- **`composer bench` script** — uses `--report=aggregate` (FAQ-026 compliant);
  benchmark runs without error.

## Candidate knowledge-base entries

### Candidate 1 — strcspn/strpbrk mask is a literal char set, not a range

- **Title:** `strcspn()`/`strpbrk()` take a literal character mask — `..` ranges
  are NOT supported (unlike `addcslashes`/`trim` charlists)
- **Tags:** `http`, `security`, `performance`, `benchmarks`
- **Trigger:** writing or reviewing a byte-mask argument to `strcspn`/`strpbrk`,
  or temping to "shorten" an explicit byte list into a `\xNN..\xMM` range
- **Paragraph:** `strcspn($haystack, $mask)` and `strpbrk($haystack, $mask)` treat
  `$mask` as a literal set of bytes — `php_charmask` is not used, so `..` is not a
  range operator here (verified: `strcspn('M','A..Z') === 1`, i.e. `M` is not
  matched; only the literal bytes `A`, `.`, `Z` are). Writing `"\x00..\x08"`
  expecting bytes 0–8 would silently match only `{0x00, '.', 0x08}` and let bytes
  1–7 through — a security regression in a control-character filter. List every
  rejected byte explicitly, as `RequestConverter::HEADER_VALUE_CONTROL_CHARS`
  does (#630). By contrast `addcslashes($s, "\x00..\x1F\x7F")` *does* honour `..`
  as a range, so the exception-message escaping next to the filter is correct with
  range syntax. Do not unify the two styles. Discovered while verifying the
  `preg_match` → `strcspn` swap in #630.
