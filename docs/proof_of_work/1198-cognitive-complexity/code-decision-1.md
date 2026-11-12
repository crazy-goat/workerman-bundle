# #1198 stage 1: cognitive-complexity gate at 50 / 150, round 1 (coder-high)

## What changed

- `composer.json` / `composer.lock`: `tomasvotruba/cognitive-complexity` is pinned to `1.3.0` in `require-dev`, the same way as `phpstan/phpstan` and `rector/rector`.
- `phpstan.neon.dist`: the file now opens with `includes: - vendor/tomasvotruba/cognitive-complexity/config/extension.neon`. The include has to be explicit because `phpstan/extension-installer` is not installed. Without it, the `allow-plugins` entry in `composer.json` does nothing. The limits are set under `parameters: cognitive_complexity: function: 50, class: 150`.
- `src/Phar/ExcludePattern.php`: `guardAgainstNestedUnboundedQuantifiers()` is now a `match` dispatch on the current byte. Each token kind has its own helper: `skipCharacterClass`, `openGroup`, `closeGroup`, `isQuantifierAt`, `consumeQuantifier` and `consumeBraceQuantifier`. The exception and its message come from the same `rejectNestedUnbounded()` as before.
- `bin/check-upgrade-exceptions.php`: `checkUpgradeExceptionsDeclaredTypes()` now only walks the directory. The per-file token scan moved into `checkUpgradeExceptionsDeclaredTypesInTokens()`. That function uses `…IsTypeKeyword`, `…IsTrivia`, `…FollowsDoubleColon`, `…ReadDeclaredName` and `…ReadHeritage`.
- `bin/kb-lint.php`: `main()` now does three things: it walks `KB_FILES`, merges the results and picks the report format. The rest moved into helpers:
  - `lintFile()`: per-file work and the line budget
  - `checkEntries()`: validation, duplicate ids and stale entries
  - `checkIndex()`: the index error and fix branches
  - `findDuplicates()`
  - `printJsonReport()` and `printTextReport()`
- `tests/Phar/ExcludePatternTest.php`: characterisation tests, written before the refactor:
  - 12 extra rejections, one for each group kind and each quantifier form
  - 16 acceptances with a match assertion
  - one test that pins the exact rejection message
- `tests/LintScopeTest.php`: a new gate test, `testCognitiveComplexityGateIsWiredAndOnlyRatchetsDown`. It checks:
  - the extension include is present
  - both limits are set, with `function <= 50` and `class <= 150`, so lowering a limit later needs no test change but raising one fails
  - there is no `baseline`, no `complexity.` ignore and no complexity message ignore
  - `composer.json` pins `1.3.0`
- Docs:
  - `CONTRIBUTING.md` Code Standards: the limits, that the limit is inclusive, that closures count into the enclosing method, the ratchet-only policy and the paths it covers
  - the PHPStan line of the lint description in `AGENTS.md`
  - a `CHANGELOG.md` entry under `[Unreleased] → Changed` (#1198)

## Scores (before → after)

| Function | Before | After |
|---|---|---|
| `ExcludePattern::guardAgainstNestedUnboundedQuantifiers()` | 72 | **3** (helpers: skipCharacterClass 4, openGroup 5, closeGroup 3, isQuantifierAt 2, consumeQuantifier/consumeBraceQuantifier ≤ 1; class total 25) |
| `checkUpgradeExceptionsDeclaredTypes()` | 67 | **6** (InTokens 8, ReadHeritage 11, ReadDeclaredName 6, FollowsDoubleColon 3, IsTypeKeyword 2, IsTrivia 1) |
| `bin/kb-lint.php main()` | 57 | **5** (lintFile 3, checkEntries 7, checkIndex 7, findDuplicates 9, printTextReport 5, printJsonReport 1) |

None of the three resisted the refactor. Every new function scores 11 or lower, which is already below the stage-4 limit of 20.

The limit is inclusive: `SfxDownloader` has a class score of exactly 150 and passes with `class: 150`. I verified this with a full `phpstan analyse` run, which reported no errors.

## Input for the next stages (measured after this PR, not committed)

I measured these with a temporary config that included `phpstan.neon.dist` and set `function: 20, class: 100`.

**Above 40 (stage 2):**
- `ServerWorker::__construct()` 48
- `bin/kb-lint.php parseFile()` 41

**Above 30 (stage 3, in addition):**
- `GithubWorkflowsTest::testMatrixRewriteExclusionsCoverOffSchemeSymfonyPackages()` 36
- `bin/check-changelog.php validateChangelogLines()` 34
- `checkUpgradeExceptionsMain()` 32
- `bin/kb-lint.php validateEntry()` 31

**Above 20 (stage 4, in addition):**
- `kb-lint parseFrontMatter()` 30
- `checkExceptionUsageDeclaredTypes()` 29
- `WorkermanCompilerPass::prepareMiddlewares()` 29
- `tests/Fixtures/sigchld_test_runner.php testSchedulerWorkerHandler()` 29
- `checkExceptionUsageMain()` 28
- `NoNarrativeCommentsFixer::removeComment()` 28
- `SfxDownloader::removeExtractedEntries()` 27
- `FileMonitorWatcher::globToRegex()` 27
- `SfxDownloader::fetch()` 26
- `BinaryFileResponseStrategy::scheduleFileCleanup()` 25
- 22 each:
  - `WorkermanCompilerPass::normalizeTaskTag()`
  - `RequestConverter::processFileNode()` (DEC-019 binds: single traversal)
  - `InotifyMonitorWatcher::onNotify()`
  - `Runner::warmUpCache()`
  - `checkUpgradeExceptionsParseTree()`
  - `checkUpgradeExceptionsParseNamedTypes()`
- `PollingMonitorWatcher::checkFileSystemChanges()` 21

**Classes above 100:**
- `SfxDownloader` 150
- `tests/ProcessInspectorTest` 122
- `NoNarrativeCommentsFixer` 112

Note on the issue's own counts: stage 3 lists "4 more (36–31)". That matches the list above: 36, 34, 32 and 31. Stage 4 has 17 functions and 3 classes, which also matches.

## How behaviour preservation was checked

All three refactors were checked against the original code. These scripts were throwaway and are not committed.

- **ExcludePattern.** I ran a differential fuzz against a copy of the original class (`ExcludePatternOriginal`) with the outcome compared exactly: `ok`, or exception class plus full message.
  - Run 1: 300k random patterns over a mixed alphabet, with metacharacters, group openers, braces and backslashes. 0 differences, 294 nested-quantifier rejections.
  - Run 2: 300k patterns over a quantifier-heavy alphabet, including `(?<n>`, `(?<=`, `(?:`, `(?=`, `(?i)`, `{2}`, `{2,}` and `\\`. 0 differences, 3,872 rejections.
  - After `lint-fix` turned the helpers from static into instance methods, I repeated both runs with 200k patterns each and again got 0 differences.
- **check-upgrade-exceptions.** I compared the serialized output of `checkUpgradeExceptionsDeclaredTypes()` over `src/Exception`, `src`, `tests`, `vendor/symfony`, `vendor/phpstan`, `vendor/workerman` and `vendor/nikic`. That covers 1,803 declarations, including anonymous classes, enums and `::class`. The md5 matched before and after.
- **kb-lint.** I ran the original and the new script on 21 roots:
  - 10 historical versions of `docs/helpers/`
  - 11 broken variants: a missing file, no index, no index with no heading, an out-of-sync index, a duplicate id, stale entries, bad front matter, over budget, no trailing newline, a duplicate body and an empty file

  Each root ran in 5 modes: text, `--json`, `--fix`, `--fix --json`, and the root taken from `KB_LINT_ROOT`. I compared stdout, stderr, exit codes and the files left after `--fix` with `diff -r`. They were identical.
- The existing tests pass unchanged: `ExcludePatternTest`, `UpgradeExceptionsLintTest` and `tests/KnowledgeBase/*`.

## Alternatives rejected

- **A baseline or `complexity.*` ignores for the three violators.** The issue forbids both, and the gate test now pins that.
- **Moving branches into closures.** Closures count into the enclosing method, so this would not lower the score.
- **A stateful scanner object for ExcludePattern**, holding the cursor and the group stack. It would be a bigger change in a security-relevant class. Static or instance helpers that pass `$groupStack` by reference keep the change local.
- **Collapsing the two keyword checks in `check-upgrade-exceptions.php` into one `in_array` that includes `T_ENUM` and drops `defined()`.** PHP 8.2+ always defines `T_ENUM`, so it would be safe. I kept the `defined('T_ENUM')` guard anyway so the refactor stays a pure refactor.

## What I was unsure about

- **`match` vs `in_array` in the guard.** The original used `in_array($ch, ['+','*','?'], true)` and `===` comparisons. `match` is also strict, so this is equivalent, and the fuzz confirms it.
- **`$inner[$x] ?? ''` instead of `$x < $length && $inner[$x] === …`.** `??` on a string offset uses isset semantics, so an offset past the end gives `''`, which is never a metacharacter. A negative offset is impossible here.
- **`strpos($inner, '>', $open + 3)`** can only be reached when `$open + 2 < $length`, so the offset is at most `$length`. That is the same ValueError-free range as the original.
- **Rector `LocallyCalledStaticMethodToNonStaticRector`.** It turned the static helpers into instance methods. `ExcludePattern` is `final readonly` with no mutable state, so this changes nothing, but it needed two `lint-fix` passes (see findings).
- **The `kb-lint` report shape.** `main()` still `exit`s, as before. The report helpers return the exit code instead of exiting themselves, and `main()` passes that code to `exit()`. The output order is the same as before, as the diff harness confirmed: errors are parse, then entry, then index, per file; warnings are root-env, then per-file budget, then index fix, then duplicates.
- **The gate test uses `<=` for the limits** instead of pinning the exact current values. It catches a raised limit, and later stages only have to edit `phpstan.neon.dist`. The cost is that it cannot detect a limit being lowered in the docs but not in the config. `CONTRIBUTING.md` names the current values, so later stages must update it too.
