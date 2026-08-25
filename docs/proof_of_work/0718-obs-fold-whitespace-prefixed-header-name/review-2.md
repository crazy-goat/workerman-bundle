# #718 — Code review, round 2 (review-critical, security-relevant)

Reviewer round-2 verification of commit `da85f76`
(`fix(dto): drop any whitespace-containing header name, not just OWS-padded`).
Parent session applied the round-2 fix and recorded round-1 statuses in
`findings-review.md` (bottom block). This file is the independent reviewer
confirmation.

---

## Scope of round 2

Single-line guard change in `src/DTO/RequestConverter.php` `buildServerHeaders()`,
plus a docblock bullet, plus the round-1 coder-note correction already landed
in `findings-coder.md`. Review target vs `origin/master`:

```
src/DTO/RequestConverter.php
- guard widened: if (\strpbrk($name, " \t") !== false) { continue; }
- docblock: + bullet "Header names containing whitespace (obs-fold / space-padded)…"
tests/RequestConverterTest.php
- + testObsFoldWhitespacePrefixedHeaderNameIsNotForwardedUnderSpaceKey
- + testTrailingWhitespaceHeaderNameIsDropped
- + testInternalSpaceHeaderNameIsDropped
docs/proof_of_work/0718-…/findings-coder.md  (F-2 correction)
```

---

## Round-1 findings — independent verification

| ID | Location | Claimed status | Reviewer verdict | Evidence |
|----|----------|----------------|-----------------|----------|
| F-1 | `src/DTO/RequestConverter.php` guard (was `:227`) internal-space leak `HTTP_X_FOLD BAR` | FIXED (widened guard) | **FIXED** | Guard is now `\strpbrk($name, " \t") !== false` → `continue`. Empirically: a buffer `"X-Fold Bar: v"` keeps key `x-fold bar` in Workerman (`var_export` confirmed `array('host'=>'example.com','x-fold bar'=>'v')`), so it reaches the guard and is dropped. `testInternalSpaceHeaderNameIsDropped` asserts both `HTTP_X_FOLD BAR` and `HTTP_X_FOLD_BAR` absent → passes. The earlier mechanism (`$name !== trim($name)`) indeed could NOT catch internal space; the new mask does. |
| F-2 | `findings-coder.md` false "rejected by Workerman tokenisation" claim | FIXED | **FIXED** | `findings-coder.md` now carries explicit correction (2026-08-25): the earlier claim was wrong, verified via `RequestConverter::toSymfonyRequest()`, and the leak is described as real and closed in round 2. No code behavior rides on this doc. |
| F-3 | `buildServerHeaders` docblock asymmetry | FIXED | **FIXED** | The docblock now lists "Header names containing whitespace (obs-fold / space-padded) are discarded so no `$_SERVER` key with a literal space is forwarded (RFC 7230 §3.2.4)" — symmetric with the underscore bullet. |
| F-4 | `testWhitespacePrefixedDuplicateHeaderIsStillJoined` only asserts joined value | deliberately NOT fixed | **NOT A DEFECT (confirmed non-blocking)** | The combined absence+value case is now covered by `testObsFoldWhitespacePrefixedHeaderNameIsNotForwardedUnderSpaceKey` (asserts `HTTP_ X_FOLD` absent AND `HTTP_X_FOLD => 'a, b'`). Pre-existing join test left as-is; reviewer agrees it is non-blocking — no production behavior depends on the extra assertion. |

---

## NEW-issue hunt (round-2 change only)

### 1. Is `\strpbrk($name, " \t")` correct?
`strpbrk()`'s second argument is a literal byte set, not a regex/range.
`" \t"` therefore means "a literal space OR a literal tab" — exactly the two
OWS bytes of RFC 7230. Correct, and matches the FAQ-027 warning (no `..`
range trap here). Verified behaviorally: space and tab names each hit the
`continue`.

### 2. Any legitimate header name wrongly dropped?
RFC 7230 §3.2.6: a field-name is a `token` (1+ `tchar`). `tchar` excludes
SP and HTAB, so **no** valid header name can contain a space or tab.
The mask can therefore only drop malformed names — strictly safe, no false
positives for RFC-conformant clients. (No attempt is made to drop other
whitespace such as VT/FF; Workerman cannot emit those in a header name since
they are not valid token chars. Out of scope and not a regression.)

### 3. Does `testInternalSpaceHeaderNameIsDropped` actually exercise the drop?
Yes. Confirmed Workerman returns the untrimmed `x-fold bar` key (not ignored),
so the header reaches the guard. The assertion `assertArrayNotHasKey('HTTP_X_FOLD BAR')`
would FAIL if the guard were absent — i.e. the test cannot sail past the drop.
Both the malformed space-key and any silently-trimmed `HTTP_X_FOLD_BAR` are
asserted absent. Test passes (4/4 in the #718 subset, 7 assertions).

### 4. PHPStan level 8
`vendor/bin/phpstan analyse src/DTO/RequestConverter.php --no-progress` →
`[OK] No errors`.

### 5. PSR-12 / comment accuracy
Guard comment is accurate and grammatical; em-dash used consistently. Docblock
bullet phrasing matches behavior. No PSR-12 issue spotted in the diff hunk.

### 6. Legitimate duplicate-join slow path preserved?
Yes. The drop sits *before* `$key` construction and the duplicate-join block.
`testObsFoldWhitespacePrefixedHeaderNameIsNotForwardedUnderSpaceKey` confirms
`X-Fold: a` + ` X-Fold: b` → `HTTP_X_FOLD => 'a, b'` while `HTTP_ X_FOLD` is
absent. Full `RequestConverterTest` (95 tests / 550 assertions) green.

---

## Verdict

**CLEAN.** All three fixable round-1 findings are correctly fixed in the
current diff (F-1 by the widened `strpbrk` guard, F-2 by the coder-note
correction, F-3 by the docblock bullet); F-4 is a correctly-judged
non-blocking deliberate non-fix with coverage superseded by a new test. The
round-2 change introduces **no new defects**: the guard is RFC-safe, the new
regression test genuinely exercises the drop, PHPStan level 8 is clean, PSR-12
holds, and the legitimate duplicate-join slow path is preserved.

## Automated checks that gate this
- `vendor/bin/phpstan analyse src/DTO/RequestConverter.php` (level 8) — clean.
- `vendor/bin/phpunit tests/RequestConverterTest.php` — 95 tests, green.
  Specific regression guards: `testInternalSpaceHeaderNameIsDropped`,
  `testTrailingWhitespaceHeaderNameIsDropped`,
  `testObsFoldWhitespacePrefixedHeaderNameIsNotForwardedUnderSpaceKey`.
- IDE/PHPUnit fixture rule per FAQ-012 (real byte in the name) — satisfied by
  all three new tests (literal space/tab in the name).
