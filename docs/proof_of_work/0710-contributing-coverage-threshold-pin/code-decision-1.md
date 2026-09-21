# Code decision 1 — pin CONTRIBUTING coverage threshold

Issue: #710 — CONTRIBUTING.md coverage threshold (80%) is unpinned prose and
can drift stale against `composer.json` `coverage:check`.

## Approach

Added `testContributingCoverageThresholdMatchesComposer()` to
`tests/BinDirectoryTest.php`, the doc-assertion file that already pins the
PHPStan level against `phpstan.neon.dist` (#693). The test:

1. Reads `composer.json`, decodes it with `json_decode(..., JSON_THROW_ON_ERROR)`
   and `assertIsArray`, then pulls `scripts["coverage:check"]`.
2. Iterates the script list and extracts the trailing numeric argument to
   `bin/check-coverage.php` with
   `preg_match('/check-coverage\.php\s+\S+\s+(\d+(?:\.\d+)?)/', ...)`.
   `assertNotNull` on the result so a reworded script fails loudly instead of
   testing nothing.
3. Converts the float to the prose display form: an integral value drops the
   `.0` (`80.0 -> "80"`), otherwise one decimal is kept
   (`85.5 -> "85.5"`).
4. Splits `CONTRIBUTING.md` on `\R` and, for **every** line containing
   `line-coverage threshold`, asserts the line contains `<display>%`. A
   counter asserts at least one matching line exists, so removing the phrase
   fails rather than passing vacuously.

## Rejected alternatives

- **Put it in `tests/CoverageCiGateTest.php`.** That file owns the CI/workflow
  gate mechanics. Issue #710 and the #693 precedent both place doc-vs-config
  assertions in the doc-assertion file (`BinDirectoryTest`), which already has
  `$this->projectDir` and two CONTRIBUTING assertions. Keeping doc pinning in
  one file avoids splitting the same concern.
- **`assertStringContainsString("$threshold%", $contrib)` on the whole file.**
  That only requires one occurrence to match; the second prose occurrence
  could drift stale unnoticed. Iterating matching lines pinches both.
- **Reuse the regex from `CoverageCiGateTest` via a shared helper.** Not worth
  cross-test coupling for a one-line regex; duplicating it keeps the test
  self-contained and the failure readable.
- **Edit `CONTRIBUTING.md` / `composer.json`.** Not needed — they are already
  consistent (80% / 80.0). The test is the deliverable.

## Uncertainties

- The display normalisation intentionally mirrors the prose convention
  ("80", "85.5"); a threshold such as `80.25` would be rendered `"80.3"` by
  `number_format(..., 1)`, which would not match a literal `80.25%` in the
  doc. The issue explicitly specifies one decimal, so this is accepted; a
  future non-one-decimal threshold would surface as a test failure and force
  a deliberate decision.
- `$threshold === floor($threshold)` is exact for the values the script
  accepts; it is not a general float-equality concern here.

## Verification

- `phpunit tests/BinDirectoryTest.php` — 15 tests, 45 assertions, OK.
- `phpunit tests/CoverageCiGateTest.php` — 6 tests, 30 assertions, OK.
- Mutation checks: changing `80.0 -> 50.0` in `composer.json` and
  `**80%** -> **90%**` in CONTRIBUTING.md each failed the new test with the
  expected message; both files reverted clean.
- PHPStan level 8 on the changed file: no errors.
- `php-cs-fixer fix --dry-run`: 0 of 1 files need fixing.
