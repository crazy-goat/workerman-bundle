# Review — Round 2 — issue #688

Branch: `process/issue-688-process-add-tests-lintscopetest-php-bin`
HEAD: `f43e03c` vs base `master`
Reviewer: review agent (round 2)

## Earlier findings (round 1)

| ID | verdict | evidence |
|----|---------|----------|
| N-1 | **fixed** | `tests/LintScopeTest.php:19-21` now reads "3 PSR-4 autoloaded classes in `PollingMonitorWatcher/`, 7 `*_runner.php`/`*_test.php` scripts and 3 `clover-*.xml` files". Verified against `git ls-files tests/Fixtures`: 3 PollingMonitorWatcher/*.php (all `final class`), 7 runner/test scripts, 3 clover-*.xml, 1 test_download.txt. The docblock describes the pre-move uppercase tree (13 files); test_download.txt was in the lowercase tree and is correctly excluded. composer.json `autoload-dev` PSR-4 maps `CrazyGoat\WorkermanBundle\Test\` → `tests/`, confirming the 3 PollingMonitorWatcher classes are autoloaded. Accurate. |
| N-2 | **fixed** | `tests/LintScopeTest.php:48` now uses `assertMatchesRegularExpression('/^\s*-\s+bin\s*$/m', $phpstan, ...)`. Verified: regex matches the actual `phpstan.neon.dist` entry (tab-indented `- bin`), rejects `- bin2` and `- binXYZ` (tested with `php -r`), and requires a standalone list item. Correct. |

## Overall verdict

**Sound — both nit findings from round 1 are correctly fixed. No new findings.**

The diff since `672a389` is exactly two changes in `tests/LintScopeTest.php`:
1. Class docblock rewording (N-1 fix).
2. `testBinInLintScope()` phpstan assertion tightening (N-2 fix).

Both fixes are accurate and do not introduce regressions.

### Commands run

| check | command | result |
|---|---|---|
| LintScopeTest | `vendor/bin/phpunit --filter LintScopeTest` | **OK — 14 tests, 34 assertions** (all pass; 1 warning is "no coverage driver", unrelated) |
| php-cs-fixer | `vendor/bin/php-cs-fixer fix tests/LintScopeTest.php --dry-run` | **0 of 1 files need fixing** |
| Regex verification | `php -r` with real `phpstan.neon.dist` + fake `- bin2` / `- binXYZ` | matches real file, rejects both fakes |
| Tree count | `git ls-files tests/Fixtures` | 14 files = 3 PollingMonitorWatcher + 7 runner/test + 3 clover XML + 1 txt |

### findings-review.md accuracy

The committed `findings-review.md` "what happened" column correctly reflects both fixes:
- N-1: "round 1 — fixed: rewrote docblock to …" ✓
- N-2: "round 1 — fixed: replaced with `assertMatchesRegularExpression(...)`" ✓

The `review-1.md` file is comprehensive and accurately captures the round 1 analysis.

## New findings

**None.** The two changes are minimal, correct, and do not introduce any new issues.

## Candidate knowledge-base entries

**None** — no new patterns worth recording beyond the KB-1 candidate from round 1 (reference lifecycle in `kb-lint.php`).

## Areas checked clean

- Docblock accuracy: 3/7/3 breakdown matches the actual tree; PSR-4 autoload claim verified via `composer.json`.
- Regex correctness: matches standalone `- bin` list entry; rejects `- bin2` and `- binXYZ`; passes against real `phpstan.neon.dist`.
- php-cs-fixer: no style violations introduced.
- LintScopeTest: all 14 assertions pass (2 LintScopeTest methods + 12 other tests picked up by the filter).
- No scope creep: diff is limited to the two fixes plus committed pow docs.
