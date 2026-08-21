# Review round 2 — #628, commit bdfa8a5 (review)

Scope: round-2 delta of bdfa8a5 (= HEAD) on top of 5fa042a — one assertion
line in tests/RequestConverterTest.php plus the round-1 record files
(review-1.md, findings-review.md). Working tree clean at HEAD = bdfa8a5
before this review wrote its own report. Read-only review; nothing committed.

## 1. Disposition of prior findings (findings-review.md read first)

Exactly one prior finding existed (round 1, nit):

| Finding | Status | Evidence |
|---|---|---|
| tests/RequestConverterTest.php:1119-1121 — characterisation test pinned only pair count + first/last values (`c0000`/`c1000`); middle-pair survival unpinned; one `assertSame('v0500', …)` would close it | **fixed** | `git show bdfa8a5` adds exactly `$this->assertSame('v0500', $symfonyRequest->cookies->get('c0500'));` at tests/RequestConverterTest.php:1119, between the first (`c0000`, line 1118) and last (`c1000`, line 1120) assertions — verbatim the prescribed fix. The status edit in findings-review.md ("added between first and last assertion", "targeted test green (4 assertions)") is accurate against both the diff and the test run below. |

No other prior findings existed ("(no other findings)" in
findings-review.md); nothing else to revisit.

## 2. Does the fix actually strengthen the pin?

Yes. Before: `assertCount(1001)` + first/last values only. After: interior
content (`c0500 => v0500`) is pinned too, so an implementation that drops or
mangles middle pairs while preserving the pair count now fails — previously
only count changes and boundary-pair damage were caught. The test now pins
positions 0, 500, and 1000 of the 1001-pair header: a genuine strengthening
of the cap-detector, not a cosmetic addition.

Observation (not a finding): round 1's motivating example ("an
implementation keeping only `c0000` and `c1000` would pass") was imprecise —
`assertCount(1001, …)` already failed for such a pure drop. The real gap the
nit gestured at was unpinned *content* of interior pairs, which the added
assertion closes. The fix is correct and sufficient regardless; no action
needed on the historical wording.

## 3. New issues in the round-2 delta

- Targeted test: `vendor/bin/phpunit --filter
  testCookieParsingIsNotCappedAtMaxInputVars` → OK, 1 test, **4 assertions**
  (count + 3 value pins), 0.034 s / 36 MB. Sole warning is the
  environmental "No code coverage driver available" (pre-existing,
  unrelated to this diff).
- Style: `vendor/bin/php-cs-fixer fix tests/RequestConverterTest.php
  --dry-run --diff` → "Found 0 of 1 files that can be fixed". The added
  line matches its neighbours byte-for-byte in indentation, quoting, and
  call shape.
- Commit hygiene: bdfa8a5 touches only tests/RequestConverterTest.php (+1
  line) and the two proof-of-work records; no src/ or docs/security.md
  changes, so the docs-only resolution of #628 remains intact.
- Claims in the findings-review.md status edit verified: "green
  (4 assertions)" ✓, "between first and last assertion" ✓.

## Verdict

APPROVE. The single round-1 nit is fixed exactly as prescribed, the pin is
strictly stronger, the targeted test is green, and the one-line delta is
style-clean. No new findings.
