# Review 1 — #557 RequestConverter raw-header re-parse gate

Reviewer: review-critical subagent (round 1). Read-only. Branch
`perf/issue-557-requestconverter-re-parses-the-raw-heade`, commit `d3df459`.

## Scope

Diff vs `master`:

- `src/DTO/RequestConverter.php` — new `rawHeadMayHaveDuplicates()` private
  static gate; `buildServerHeaders()` now skips `parseRawHeaderLines()` when
  the gate returns false.
- `src/Http/Request.php` — new `addedHeaderCount` tracking.
- `tests/RequestConverterTest.php` — 10 new tests.
- `CHANGELOG.md`, PoW `code-decision-1.md`, `findings-coder.md`.

KB entries read (tag-index dispatch): FAQ-012, DEC-004, DEC-005, DEC-010.
No violations of those entries found in the diff. DEC-010 (cookie parsing /
#217) is untouched and its regression tests pass on this branch.

## 5-point core-correctness verification

The claim under review: `rawHeadMayHaveDuplicates()` can only produce false
positives (re-parse when not needed), never false negatives (skip the
re-parse when duplicates exist). A false negative is a security regression
because the #217 duplicate-`Cookie` smuggling class would be skipped.

Evidence is the vendored Workerman at
`vendor/workerman/workerman/src/Protocols/Http/Request.php` (the version
pinned by `composer.lock`).

| # | Question | Verdict | Evidence (vendor line) |
|---|----------|---------|------------------------|
| 1 | obs-fold / whitespace-prefixed lines: does `parseHeaders()` treat them as a new header (not a continuation), and does the gate's whitespace-name override catch all such cases? | **VERIFIED** | `parseHeaders()` L517 `$key = strtolower($parts[0])` — **no trim**, so `" X-Fold: b"` becomes key `" x-fold"` (with leading space), distinct from `x-fold`. The gate iterates `$workermanHeaders` keys and forces a re-parse when `(string) $name !== trim((string) $name)`; `strtolower` does not alter whitespace bytes, so any padded key is caught. RFC 7230 §3.2.4 obs-fold is *not* unfolded by either parser, but the gate does not need folding — it only needs to detect that the two parsers' key sets diverge, which the name check does. Test `testWhitespacePrefixedDuplicateHeaderIsStillJoined` confirms `'a, b'` survives. |
| 2 | Header lines without `:` (garbage): do they inflate the CRLF count and force the slow path (safe), or can they coincidentally balance? | **VERIFIED (safe)** | `parseHeaders()` L514 `if (!isset($parts[1])) continue;` skips colon-less lines; `parseRawHeaderLines()` L356 `if (str_contains($line, ':'))` likewise skips them. Both parsers skip → the line is counted in N (CRLF count) but not in d (distinct keys) → `N ≠ d` → re-parse. A garbage line can only increase N, never decrease d, so it can never make a duplicate-bearing request look unique. Test `testColonlessGarbageLineKeepsHeaderConversion` covers it. The only way a "garbage" line enters d is if it contains a `:`, in which case it is a normal header line and counted by both parsers symmetrically. |
| 3 | `addedHeaderCount()`: does `setHeader()` increment only for genuinely new names (not overwrites), and does `resetHeaders()` reset the counter so it does not leak across keep-alive requests? | **VERIFIED** | `src/Http/Request.php` L78 `if (!array_key_exists($name, $this->data['headers'])) { ++$this->addedHeaderCount; }` — increments only when the lowercased name is absent; overwrites (name present) do not increment. `resetHeaders()` L88-89 calls `parseHeaders()` (which resets `data['headers']` to the raw parse, vendor L496) and sets `addedHeaderCount = 0`. Crucially, `vendor/.../Http.php` L297 constructs a **fresh** `Request` per `decode()`, so the counter is per-instance and cannot leak across keep-alive requests even without the `finally` reset; the `finally` reset in `ServerWorker.php` L143 is defense-in-depth and also correct. The counter cannot exceed `count($workermanHeaders)` because each increment is paired with a map insertion, so `count - addedHeaders ≥ 0` and cannot go negative; even a corrupted counter fails open (mismatch → re-parse). Tests `testRawHeadMayHaveDuplicatesAccountsForMiddlewareAddedHeaders` and `testRawHeadMayHaveDuplicatesIgnoresMiddlewareOverwrites` cover both branches. |
| 4 | The `- 1` vs `0` CRLF-count offset: the coder says `rawHead()` excludes the trailing CRLF so the offset is 0. | **VERIFIED** | `rawHead()` L439 `return $this->data['head'] ??= strstr($this->buffer, "\r\n\r\n", true);` — `strstr(..., true)` returns the substring **before** the first `"\r\n\r\n"`, excluding the terminator. So `rawHead()` never ends with `\r\n` and `substr_count($rawHead, "\r\n")` equals the number of lines after the request line with no adjustment. Using `- 1` (as the issue body suggested) would make every well-formed request look one line short and force 100% slow-path (correct but useless). The coder's offset of 0 is correct for this vendored version; the gate is also self-correcting on other versions (an extra CRLF in N → mismatch → slow path). |
| 5 | Can `rawHead()` return `false` (from `strstr`) and reach the gate? | **VERIFIED (not a regression)** | L439 `strstr(...)` returns `false` when the buffer has no `"\r\n\r\n"`, which would violate the `: string` return type with a `TypeError`. However `Http::decode()` (vendor L289-303) is only called by Workerman's connection layer after the full head (including the blank-line terminator) has been received, so the terminator is guaranteed in practice. The pre-gate code already called `parseRawHeaderLines($rawRequest->rawHead())`, so the gate introduces **no new exposure**: both paths call `rawHead()`. The coder flagged this correctly as a latent vendor bug (`findings-coder.md` item 1); not actionable in this PR. |

**Conclusion on the core claim:** VERIFIED. The gate is sound. Every input
shape that could make the counts coincide while a duplicate exists is
covered:

- obs-fold / padded names → name check forces re-parse (point 1).
- garbage / colon-less / CR-only lines → inflate N, never d (point 2).
- middleware-added names → subtracted via `addedHeaderCount()`; overwrites
  do not change either count and keep the fast path (point 3).
- the count is exact (offset 0) for this vendored version and self-correcting
  on others (point 4).
- the one latent vendor `false`-return cannot reach the gate in practice and
  is pre-existing (point 5).

The gate fails open (when in doubt, re-parse) on every unverified shape,
which is the correct security default. The `match` block that joins
duplicate `Cookie` with `'; '`, reduces `Host`/`Content-Length`/
`Authorization`/`Transfer-Encoding` to the first value, and joins other
headers with `', '` is byte-for-byte unchanged and is reached whenever the
gate returns true.

## Findings

Numbered, high → low. `file:line | description | severity | status`.

### 1. `tests/RequestConverterTest.php:1198` | No explicit fast-path-vs-slow-path parity test | low | open

The 10 new tests assert the gate's boolean return (5 tests) and that
specific inputs produce specific server-bag values (5 tests). The
fast-path output is exercised indirectly by `testHeadersAreAvailableInServerBag`
(L412, unique headers → gate false → asserts `HTTP_HOST`, `HTTP_ACCEPT`,
`CONTENT_TYPE`, `CONTENT_LENGTH`, `CONTENT_MD5`) and
`testMiddlewareOverwrittenHeaderIsUsedWhenNoDuplicates` (L1327). However
there is **no test that takes a single unique-header input, runs it through
`buildServerHeaders` with the gate enabled and with the gate forcibly
disabled, and asserts the two server bags are identical**. That is the
central correctness claim of the optimization (gate=false ⇒ output ==
pre-optimization output) and a regression in the fast-path branch of the
`foreach` (e.g. someone changing `$rawHeaders[$nameLower] ?? null` to
something that hits when it should miss) would not be caught by the current
suite. Suggested: a test that builds a unique-header buffer, converts it
once normally (fast path) and once with a reflection-forced `$rawHeaders`
populated (slow path), and `assertSame` on the full server bag. An
automated check (a parity/property test) could plausibly have caught this
gap.

### 2. `src/Http/Request.php:44` | `addedHeaderCount` coupling leaks an implementation detail of `RequestConverter` into `Http\Request` | low | open

`Http\Request` now carries a counter whose only consumer is
`RequestConverter::rawHeadMayHaveDuplicates()`, and the property docblock
even names `RequestConverter` as the reason it exists. This is a one-way
dependency (`RequestConverter` reads `Http\Request`), not a new cyclic
coupling, and `Http\Request` already exposes `setHeader()`/`resetHeaders()`
for the middleware pipeline, so the surface is not widened dramatically.
But the counter is a performance optimization detail of the converter that
has been pushed into the HTTP request DTO. A less-coupled alternative
considered and rejected by the coder was a boolean "mutated" flag, which
would disable the optimization for overwrite-only middleware; another
alternative is to drop the counter entirely and accept the middleware
false-positive rate (re-parse whenever `count(header()) > raw-line-count`,
which over-triggers when middleware adds names). The current design is
defensible because the counter is the only option that keeps the fast path
for overwrite-only middleware, but the coupling is real and worth noting
for the retro. Not a merge blocker.

### 3. `src/DTO/RequestConverter.php:321` | `rawHeadMayHaveDuplicates()` calls `rawHead()` once and `parseRawHeaderLines()` calls it again on the slow path | nit | open

The gate calls `$rawRequest->rawHead()` (L322) for the CRLF count. When
the gate returns true, `buildServerHeaders()` calls
`parseRawHeaderLines($rawRequest->rawHead())` (L195) again. `rawHead()` is
memoized via `??=` (vendor L439), so the second call is a cheap array
lookup, not a re-parse. This is not a bug, just a tiny readability nit:
passing `$rawHead` into the gate and the parser would avoid the double
conceptual read. No action required.

### 4. `docs/proof_of_work/0557-requestconverter-header-duplicate-parse/findings-coder.md:60-64` (out-of-scope item 2) | obs-fold forwarding of whitespace-prefixed header names is real but pre-existing | low | open (agree with coder)

The coder flagged that a whitespace-prefixed header line survives as a
Workerman key with a leading space and is forwarded to Symfony under a
server key containing a literal space (e.g. `HTTP_ X_FOLD`), contrary to
RFC 7230 §3.2.4. **Real, but pre-existing** — `buildServerHeaders()` L215
`'HTTP_' . strtoupper(str_replace('-', '_', $name))` was unchanged by this
diff and would produce the same spaced key with or without the gate (the
gate forces a re-parse for these lines, so behavior is identical to before).
This PR does not regress it. The coder correctly marked it out-of-scope and
suggested a separate issue to drop such headers. I agree: file a follow-up,
do not fix here (any change would alter behavior and break the
"optimization only" scope).

### 5. `docs/proof_of_work/0557-requestconverter-header-duplicate-parse/findings-coder.md:70-76` (out-of-scope item 3) | value `ltrim` vs `trim(" \t")` divergence is real but cosmetic | nit | open (agree with coder)

`parseRawHeaderLines()` L359 uses `ltrim($value)` (default mask
`" \t\n\r\0\x0B"`) while Workerman's `parseHeaders()` L518 uses
`trim($parts[1], " \t")`. The sets differ, but the difference is only
observable on duplicate headers (where raw values are joined) and any byte
in the divergence set (`\n`, `\r`, `\0`, `\x0B`) is rejected by the control
character check at `buildServerHeaders()` L204. **Real, by-design-cosmetic.**
Worth aligning if the parser is ever touched again; not actionable here.

### 6. `docs/proof_of_work/0557-requestconverter-header-duplicate-parse/findings-coder.md:54-59` (out-of-scope item 1) | vendor `rawHead()` `false` return is real, latent, not a regression | low | open (agree with coder)

`vendor/.../Http/Request.php:439` `strstr(..., true)` returns `false`
when the buffer has no `"\r\n\r\n"`, violating `: string`. Latent because
`Http::decode()` only runs after the full head is buffered. **Real, latent,
not a regression** — the pre-gate code had the same exposure. Vendor fix
would be `?: ''`. Not actionable in this PR.

## Other checks

- **Type correctness (PHPStan level 8):** `addedHeaderCount` is `private int
  $addedHeaderCount = 0` (initialized), incremented with `++$this->...`
  (int), reset with `= 0` (int), returned via `addedHeaderCount(): int`
  (int). The gate compares `int !== int` (`substr_count` and `count` both
  return `int`, `addedHeaders` is `int`). No type holes. `composer lint`
  ran PHPStan level 8 clean.
- **PSR-12 / php-cs-fixer:** `composer lint` clean (0 of 245 files fixable).
- **Error handling:** the gate never throws on malformed input. Any
  unexpected shape (garbage, CR-only, padded names, corrupted counter)
  produces a mismatch or name-check hit → re-parse (the safe default). The
  gate honors "when in doubt, parse".
- **Test coverage:** the 10 new tests cover each boolean branch of the gate
  (false-for-unique, true-for-duplicate, true-for-colonless, true-for-padded-
  name, true-for-middleware-added, false-for-middleware-overwrite) and each
  end-to-end behavior (padded duplicate joined, colonless garbage ignored,
  middleware does not mask duplicate `Cookie`, middleware overwrite used
  when no duplicates). The pre-existing slow-path duplicate-handling tests
  (`testMultipleHostHeadersKeepsFirstOnly`,
  `testMultipleContentLengthHeadersKeepsFirstOnly`,
  `testMultipleAuthorizationHeadersKeepsFirstOnly`,
  `testMultipleTransferEncodingHeadersKeepsFirstOnly`,
  `testMultipleCookieHeadersJoinedWithSemicolon`,
  `testNonSensitiveDuplicateHeadersJoinedWithComma`,
  `testMultipleCookieHeadersWithEncodedValuesStaySeparate`) all still pass
  because the gate re-parses whenever duplicates exist. The #217 regression
  tests pass on this branch.
- **Coverage concern:** see Finding 1 — no explicit fast/slow parity
  assertion. The fast path is exercised for output correctness only
  indirectly via `testHeadersAreAvailableInServerBag`.

## KB candidate entries

Proposed for the retro step to add (not appended here):

1. **FAQ-025 — "Verify the CRLF-count offset against the vendored
   `rawHead()` before gating on it"**
   - tags: `http`, `headers`, `tests`, `vendor`
   - trigger: "gating header parsing on a raw-head line count"
   - One paragraph: The vendored Workerman `rawHead()` returns
     `strstr($buffer, "\r\n\r\n", true)`, which **excludes** the trailing
     CRLF terminator, so `substr_count($rawHead, "\r\n")` equals the number
     of header lines after the request line with **no `- 1` adjustment**.
     The issue body for #557 suggested `substr_count(...) - 1`, which is
     wrong for this version (it would make the gate always true — correct
     but useless). Before gating any parser on a raw-head line count, read
     the vendored `rawHead()` and confirm the terminator is excluded; the
     gate must also be self-correcting (an off-by-one can only ever force
     the slow path, never skip it).

2. **DEC-013 — "Optimization gates must fail open (re-parse) on every
   unverified input shape"**
   - tags: `http`, `security`, `performance`
   - trigger: "adding a fast-path gate to a security-relevant parser"
   - One paragraph: The #557 gate skips the raw-header re-parse only when
     the CRLF-line count exactly equals the distinct-parsed-name count
     (minus middleware additions) AND no parsed name has surrounding
     whitespace. Every other shape — garbage lines, CR-only lines,
     obs-fold, middleware additions, a corrupted counter — must fall back
     to the full re-parse. The duplicate-`Cookie` smuggling class (#217,
     DEC-010) depends on the re-parse running whenever a duplicate exists,
     so the gate's invariant is "false positives allowed, false negatives
     forbidden". Any future optimization gate on a security-relevant parser
     must preserve that invariant and must be proven against every input
     shape that could make the fast path's counts coincide with a
     duplicate-bearing input.

## Merge recommendation

**GO WITH FIXES** — The core correctness claim is verified against the
vendored source on all 5 points, all gates pass (`composer lint`, full
test suite), and the #217 regression tests pass. The single substantive
fix-before-merge is Finding 1: add an explicit fast-path-vs-slow-path
parity test that asserts the server bag is identical whether the gate is
allowed to skip the re-parse or forced to re-parse, for the same
unique-header input. Findings 2-6 are low/nit and can be addressed in the
retro or follow-up issues.
