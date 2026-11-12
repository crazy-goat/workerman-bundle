# #1198 stage 3: cognitive-complexity function limit 40 → 30, round 4 (coder-high)

Branch `refactor/issue-1198-cognitive-complexity-30`, from master `275a67a` (stage 2 merged as #1200).

## What changed

- `phpstan.neon.dist`: `cognitive_complexity.function: 30`. `class` stays at 150.
- `tests/CognitiveComplexityGateTest.php`: `FUNCTION_LIMIT_CEILING = 30`.
- `CONTRIBUTING.md`: "currently 30 per function or method and 150 per class".
- `CHANGELOG.md`: the existing `(#1198)` line now says 30 and lists `bin/check-changelog.php` and a `GithubWorkflowsTest` method. No second entry.
- `tests/GithubWorkflowsTest.php`: `testMatrixRewriteExclusionsCoverOffSchemeSymfonyPackages()` is now a sequence of calls to private helpers in the same class:
  - `symfonyConstraints()`, `dominantConstraint()`, `partitionByConstraint()`
  - `symfonyRewriteSteps()`, `sedExclusions()`
  - `assertOffSchemePackagesAreExcluded()`, `assertExclusionsTargetOnlyOffSchemePackages()`, `assertRewriteClassMatchesEveryPackage()`
  - `anyPatternMatches()` and `rewriteCharacterClass()`, which replace the two `$covered` / `$matchesOffScheme` flag loops and the `break 2` search

  Every assertion is kept, with the same message text, in the same order. The test still makes 41 assertions on the real repository.
- `bin/check-changelog.php`: `validateChangelogLines()` keeps the fence check and then spreads, in the original order:
  - `unreleasedHeadingViolations()`
  - `releasedHeadingViolations()`, which uses `parseReleasedHeading()`
  - `duplicateSubheadingViolations()`
  - `missingReferenceViolations()`
- `bin/check-upgrade-exceptions.php`:
  - `checkUpgradeExceptionsMain()` (32) collects its failures from `…NameFailures()`, `…EdgeFailures()`, `…ExplicitParentFailures()` and `…NamedTypeFailures()`. The heritage suffix (`parent` or `(nothing)`, plus `implements …`) that two messages built inline is now `…DescribeHeritage()`.
  - Taken along because the file was already open and the split was natural:
    - `checkUpgradeExceptionsParseTree()` (22) now uses `…ReadUpgradeFile()`, `…ReadTreeBlock()` and `…ParseTreeLine()`.
    - `checkUpgradeExceptionsParseNamedTypes()` (22) now uses `…NamedTypeScope()` plus a `UPGRADE_EXCEPTIONS_NAMED_TYPE_PATTERNS` constant that holds the three `use` / `catch` / `new` regexes. The three identical loops became one loop. A sorted diff of every `'/…/'` literal in the old and new file is identical, so no regex changed.
- `tests/UpgradeExceptionsLintTest.php`: three new tests for error branches of the refactored tree parser that had no test:
  - `testAHeadingWithoutATextBlockFails`: "no ```text block found"
  - `testAnUnparsableTreeLineFails`: "cannot parse tree line"
  - `testATreeLineWithoutAParentLevelFails`: "cannot determine parent of tree line"

  Each test asserts exit code 1 and the exact error line.

## Scores (before → after)

| Function | Before | After |
|---|---|---|
| `GithubWorkflowsTest::testMatrixRewriteExclusionsCoverOffSchemeSymfonyPackages()` | 36 | **0** (helpers ≤ 6; class 34) |
| `validateChangelogLines()` | 34 | **1** (helpers ≤ 9) |
| `checkUpgradeExceptionsMain()` | 32 | **7** (helpers ≤ 8) |
| `checkUpgradeExceptionsParseTree()` (taken along) | 22 | **11** (helpers ≤ 5) |
| `checkUpgradeExceptionsParseNamedTypes()` (taken along) | 22 | **12** (`…NamedTypeScope` 3) |

No other function was above 30 at the start of the stage. Nothing resisted. The highest function left in `check-upgrade-exceptions.php` is `checkUpgradeExceptionsReadNameList()` at 20, and in `check-changelog.php` it is `outsideFences()` / `versionBlocks()` at 10. Neither script needs reopening at stage 4.

## Remaining above 20 (stage 4), measured after this PR with a temporary config, not committed

Functions (14):

- 29 each:
  - `bin/check-exception-usage.php checkExceptionUsageDeclaredTypes()`
  - `WorkermanCompilerPass::prepareMiddlewares()`
  - `tests/Fixtures/sigchld_test_runner.php testSchedulerWorkerHandler()`
- 28 each:
  - `bin/check-exception-usage.php checkExceptionUsageMain()`
  - `NoNarrativeCommentsFixer::removeComment()`
- 27 each:
  - `SfxDownloader::removeExtractedEntries()`
  - `FileMonitorWatcher::globToRegex()`
- `SfxDownloader::fetch()` 26
- `BinaryFileResponseStrategy::scheduleFileCleanup()` 25
- 22 each:
  - `WorkermanCompilerPass::normalizeTaskTag()`
  - `RequestConverter::processFileNode()` (DEC-019 binds: single traversal)
  - `InotifyMonitorWatcher::onNotify()`
  - `Runner::warmUpCache()`
- `PollingMonitorWatcher::checkFileSystemChanges()` 21

Classes above 100:
- `SfxDownloader` 150 (exactly at the limit)
- `tests/ProcessInspectorTest` 122
- `NoNarrativeCommentsFixer` 112

## How equivalence was checked

- **GithubWorkflowsTest, mutation run.** A throwaway generator builds 10 scratch roots. Each root holds a copy of `.github/workflows/tests.yaml`, a copy of `composer.json`, and the old or the new test file. The test runs on its own (`--no-configuration`, `--filter` on the method). The roots are:
  1. clean
  2. the `/contracts/!` exclusion removed
  3. the exclusion widened to `/symfony/!`, which hits on-scheme packages
  4. an extra exclusion that matches no package
  5. an extra exclusion that is not a valid regex
  6. the rewrite class `[a-z-]` (no digits), with a `symfony/polyfill-php83` package added
  7. the rewrite class without `a-z`
  8. the step renamed
  9. no dominant constraint (a tie between the two constraint schemes)
  10. an off-scheme `symfony/yaml: ^7.0` with no exclusion

  The clean root passes for both the old and the new test. All 9 mutations fail for both, with the same first failure message (the message hashes match pairwise). I ran this twice, before and after `lint-fix`.
- **check-changelog, differential.** A throwaway harness loads the function part of the old and the new script in separate processes and runs `validateChangelogLines()` on:
  - 20,000 random changelogs built from 25 line fragments: headings, malformed, non-ISO and out-of-order versions, the Keep a Changelog subheadings and duplicates, entries with and without references, continuation lines, both fence styles, and a legacy entry
  - the last 60 committed versions of `CHANGELOG.md`

  `md5(serialize())` of the 20,060 results (60,235 violations) is identical, before and after `lint-fix`. `tests/ChangelogStructureTest.php` passes unchanged.
- **check-upgrade-exceptions, differential.** The old and the new script run as a CLI on 79 roots:
  - the real tree
  - for every tree entry: the entry dropped, and a wrong `(extends …)` annotation
  - an extra doc entry, a wrong nesting, prose naming undeclared types, a missing tree
  - 8 source files removed, an extra source class with `implements A, B`, an empty `src/Exception`, a missing `src/Exception`, a missing `UPGRADE.md`
  - the last 16 committed versions of `UPGRADE.md`

  stdout, stderr and exit codes compared with `diff -r` are identical (exit codes: 0 ×9, 1 ×67, 2 ×3), before and after `lint-fix`. `tests/UpgradeExceptionsLintTest.php` passes, with its existing tests unchanged.
- **The three new tests are mutation-checked.** They pass on the original script and on the refactored one. I deleted each guarded `fwrite` + `exit(1)` branch in turn, in a scratch copy, and each time exactly the matching test failed (the parse-guard mutant falls through to a later error, so the exact message assertion fails).

## Test churn

- `tests/GithubWorkflowsTest.php`: +112 / −25. These are the lines moved into helpers. No assertion was added, removed or reworded.
- `tests/UpgradeExceptionsLintTest.php`: +43 / −0, for the three new tests. Existing tests are unchanged.
- `tests/CognitiveComplexityGateTest.php`: 1 line, the ceiling constant.

## Alternatives rejected

- **Splitting `testMatrixRewrite…` into several test methods.** Each would rebuild the constraints and steps. The failure conditions would stay the same, but the maintainer asked for minimal churn, and separate methods would change the reported test names. I kept one test and moved the work into helpers.
- **Fixing the named-type scope bug** (see the findings) while touching `…NamedTypeScope()`. That would be a behaviour change in a refactor PR, so it was rejected.
- **Merging the backtick-span loop into the regex list.** It has a second filter (the identifier regex on the trimmed span), so it stays separate.

## What I am unsure about

- **`anyPatternMatches()` builds `'/' . $pattern . '/'`, the same delimiter the old inline loops used.** The two old loops differed only in which side was the loop. Their result was "any pair matches", so they are equivalent to one double loop. The mutation run covers both directions.
- **`dominantConstraint()` returns `(string) array_key_first($counts)`.** `array_count_values` turns numeric-string constraints such as `"1"` into int keys. The old code then compared `$constraint === $frameworkConstraint` with an int, so such a constraint would have fallen on the off-scheme side. The cast changes that only for a purely numeric Symfony constraint, which composer.json does not and realistically cannot hold (Symfony constraints are `^x.y` ranges). I note it rather than claim byte-identity for that input.
