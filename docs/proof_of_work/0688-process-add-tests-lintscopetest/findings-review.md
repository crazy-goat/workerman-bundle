# Findings Review — issue #688

Ledger of review findings. One entry per finding. Nothing is deleted from this
file; each round updates the "what happened" column.

| ID | file:line | what is wrong | severity | what happened to it |
|----|-----------|---------------|----------|---------------------|
| N-1 | tests/LintScopeTest.php:20 | Motivation docblock says the pre-move uppercase tree held "13 PHP classes referenced by PSR-4 autoload"; actually 13 files = 10 PHP (only 3 are PSR-4 autoloaded classes in `PollingMonitorWatcher/`, the rest are runner scripts) + 3 XML. Count 13 is right, "PHP classes"/"PSR-4 autoload" labels overstate. No functional impact. | nit | round 1 — fixed: rewrote docblock to "3 PSR-4 autoloaded classes, 7 runner scripts, 3 clover-*.xml". round 2 — confirmed fixed: verified 3 PollingMonitorWatcher classes (all `final class`, PSR-4 autoloaded via composer.json), 7 `*_runner.php`/`*_test.php` scripts, 3 clover-*.xml = 13; test_download.txt correctly excluded as the lowercase-tree file. |
| N-2 | tests/LintScopeTest.php:51 | `assertStringContainsString('- bin', $phpstan)` is a bare substring match; a `- bin2` typo would still pass (false negative). cs-fixer/rector assertions use the precise `__DIR__ . '/bin'` and are fine. Negligible impact — PHPStan errors on a non-existent analysed path anyway. | nit | round 1 — fixed: replaced with `assertMatchesRegularExpression('/^\s*-\s+bin\s*$/m', ...)` matching a standalone list entry. round 2 — confirmed fixed: regex matches real `phpstan.neon.dist` entry, rejects `- bin2` and `- binXYZ` (tested with `php -r`). |

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
  tightened to regex (N-2 fixed in round 2).
- **Cross-cutting:** no stray lowercase `fixtures` code refs (one intentional
  comment); `git ls-files tests/fixtures` empty; `tests/Fixtures` = 14;
  `test_download.txt` 100 % rename; clover XML refs already uppercase and
  untouched; no BC break (dev/test/config only); no security issue
  (`escapeshellarg`, no user input); coverage floor unaffected.
- **Lint credibility:** PHPStan level 8 / php-cs-fixer / Rector / kb-lint all run
  independently on `bin/` — pass. LintScopeTest run in isolation — pass (round 2:
  14 tests, 34 assertions).

## Round 3 — CI failure (escaped defect)

| ID | file:line | what is wrong | severity | what happened to it |
|----|-----------|---------------|----------|---------------------|
| CI-1 | bin/kb-lint.php:835 | CI lint leg (PHP 8.2, phpstan 2.2.8) reports `Result of function main (void) is used.` (`function.void`) on `exit(main(parseArgs(...)))`. Local PHP 8.4/8.5 + phpstan 2.2.8 does NOT reproduce it, nor does `phpVersion: 80200`. The trigger (from PHPStan source `FunctionCallParametersCheck.php:224`) is: the `main(...)` call is NOT a first-level statement (it is the argument of `exit(...)`), so PHPStan's void-usage check runs, and under PHP 8.2 it resolves `main`'s return as void. `pick-issue.php` passed CI because its `main(): void` is called as a bare statement `main(...);`, which is a first-level statement and is exempt from the check. This escaped rounds 1–2 because the review agents and the coder all ran on PHP 8.4/8.5, where the inference differs — a missing local-vs-CI PHP-version parity check, not bad luck. | high | round 3 — fixed: refactored `kb-lint.php` so `main()` calls `exit()` itself on every return path (3 sites), and the caller is now the bare statement `main(parseArgs($_SERVER['argv'] ?? []));` (first-level, exempt from `function.void`), matching the `pick-issue.php` pattern. The `try/catch` is retained so a `Throwable` from `parseArgs()` (or from inside `main` before it `exit`s) is still caught and reported with `exit(2)`. Tests run `bin/kb-lint.php` as a subprocess and check `proc_close`, so converting returns to `exit` is transparent to them (verified: KnowledgeBase/KbLint tests pass). |

### Automated check that would have caught this

A local lint parity check that runs `composer lint` under the **lowest supported PHP**
(8.2) before push — not just under the developer's local PHP. The CI matrix's lint
leg is the only place that runs under 8.2 today, so a PHP-version-specific PHPStan
inference gap is invisible locally. Candidate KB entry: run lint under `php:8.2`
locally (e.g. via `docker run php:8.2-cli composer lint` once per PR) before opening
the PR. Not landed in this issue (out of scope: would need a committed lock or a
`--ignore-platform-reqs` wrapper); flagged for the retro step.
