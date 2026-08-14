# Findings Review — issue #688

Ledger of review findings. One entry per finding. Nothing is deleted from this
file; each round updates the "what happened" column.

| ID | file:line | what is wrong | severity | what happened to it |
|----|-----------|---------------|----------|---------------------|
| N-1 | tests/LintScopeTest.php:20 | Motivation docblock says the pre-move uppercase tree held "13 PHP classes referenced by PSR-4 autoload"; actually 13 files = 10 PHP (only 3 are PSR-4 autoloaded classes in `PollingMonitorWatcher/`, the rest are runner scripts) + 3 XML. Count 13 is right, "PHP classes"/"PSR-4 autoload" labels overstate. No functional impact. | nit | round 1 — fixed: rewrote docblock to "3 PSR-4 autoloaded classes, 7 runner scripts, 3 clover-*.xml". |
| N-2 | tests/LintScopeTest.php:51 | `assertStringContainsString('- bin', $phpstan)` is a bare substring match; a `- bin2` typo would still pass (false negative). cs-fixer/rector assertions use the precise `__DIR__ . '/bin'` and are fine. Negligible impact — PHPStan errors on a non-existent analysed path anyway. | nit | round 1 — fixed: replaced with `assertMatchesRegularExpression('/^\s*-\s+bin\s*$/m', ...)` matching a standalone list entry. |

## High-risk areas checked clean (no finding)

- **bin/kb-lint.php `$currentBody` reference lifecycle:** `unset($currentBody)`
  before `$currentBody = null` is load-bearing (a bare `= null` would write
  through the reference and null the entry's body). Implementation includes the
  unset. Verified with a multi-entry / `####` sub-heading / fenced-block fixture:
  no body line lost or misrouted.
- **`array_slice($body, 1)` vs `array_shift` + `array_values`:** equivalent
  re-indexed list.
- **testNoCaseCollidingTrackedPaths:** `strtolower` full-path granularity correct;
  `git ls-files` is index-sourced (case-sensitive on APFS too); trailing-newline
  handled; 0 collisions on the live tree.
- **testBinInLintScope:** cs-fixer/rector assertions precise; phpstan assertion
  loose (N-2).
- **Cross-cutting:** no stray lowercase `fixtures` code refs (one intentional
  comment); `git ls-files tests/fixtures` empty; `tests/Fixtures` = 14;
  `test_download.txt` 100 % rename; clover XML refs already uppercase and
  untouched; no BC break (dev/test/config only); no security issue
  (`escapeshellarg`, no user input); coverage floor unaffected.
- **Lint credibility:** PHPStan level 8 / php-cs-fixer / Rector / kb-lint all run
  independently on `bin/` — pass. LintScopeTest run in isolation — pass (2 tests,
  6 assertions).
