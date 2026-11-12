# #1198 stage 1: review round 2

Scope: `git diff ae281ae HEAD` (the commits after "record review round 1"): `tests/CognitiveComplexityGateTest.php` (new),
`tests/LintScopeTest.php` (gate method moved out), `tests/PollingMonitorWatcherTest.php:81`, `CONTRIBUTING.md:324-335`,
and the proof-of-work files.

## Checks run

- `LINT_SKIP_AUDIT=1 composer lint`: all checks passed (PHPStan level 8 with the complexity limits, CS-Fixer, Rector,
  kb-lint, changelog, exception usage, test annotations, shellcheck, hadolint).
- `vendor/bin/phpunit --no-coverage tests/CognitiveComplexityGateTest.php tests/LintScopeTest.php tests/BinDirectoryTest.php tests/PollingMonitorWatcherTest.php`:
  68 tests, 625 assertions, OK. One skip: `BinDirectoryTest::testWaitForPortsConnectsToTheConfiguredHost`
  ("127.0.0.2 is not available" on macOS loopback), environmental and unrelated to this diff.
- I ran `BinDirectoryTest` again after writing this file and the findings file.

## Earlier findings

- **R1-1 (low): fixed.** The gate now covers what round 1 listed:
  - the inline `complexity.` ignore: `ignoreNamesComplexity()` at `tests/CognitiveComplexityGateTest.php:147-150`, with provider cases at :105-107
  - bare `-next-line` / `-line` on a declaration: `ignoreCoversDeclaration()` at :153-167, with cases at :108-112
  - an extra include: `testTheExtensionIsTheOnlyInclude` at :31-38
  - the shadowing `phpstan.neon` / `phpstan.dist.neon`: :66-71. I confirmed that `bin/lint.sh` runs PHPStan without `-c`.

  The only existing violation was `tests/PollingMonitorWatcherTest.php:81`. It now uses `@phpstan-ignore missingType.generics`, and lint stays clean. That also shows the ignore matches: PHPStan would otherwise report an unmatched ignore. `CONTRIBUTING.md` now lists what is actually enforced. Two config-level bypasses remain (R2-1). They are a different class from R1-1, so I filed them as new.
- **R1-2 (nit): fixed.** The limits are read from `parameters → cognitive_complexity` (:43). The block must hold exactly `class` and `function` (:52), and every entry must be `key: int` (:44). A decoy key outside the block cannot be read any more. `neonChildren()` matches only the key at the block's minimal indent.

## NEON reader (brittleness)

The reader fails closed on every variation I tried:

- an inline map (`cognitive_complexity: {function: 50}`) or an inline list (`includes: [...]`, `paths: [...]`) yields no entries, so the `assertSame` at :52 / :33 or the `assertContains('src')` at :87 fails
- a trailing comment (`function: 50 # x`) does not match :44, so the test fails
- tabs and spaces are both counted per character, and NEON itself rejects mixed indentation within a block
- a second top-level `parameters:` or `includes:` key is a NEON duplicate-key error before the test matters

Acceptable for a three-block reader. Rejecting a real NEON parser (it is scoped inside `phpstan.phar`) is justified.

## File discovery and false positives

- `git ls-files -- src tests benchmarks bin tools` with the paths read from the config is the right scope, and new paths are picked up. The scan is clean on the current tree, so there are no false positives on the existing codebase.
- Only `T_COMMENT` / `T_DOC_COMMENT` tokens are scanned, so heredoc and string fixtures (php-cs-fixer rule tests) are not matched. This is in line with DEC-020.
- Known over-approximations, which are acceptable and documented in code-decision-2:
  - a bare `-next-line` above a closure (`T_FUNCTION`) or above a property attribute is flagged
  - prose that mentions the directive is flagged
- Known under-approximation, not filed: PHPStan reports at the node start line, which is the modifier line. A declaration split as `public static\n    function a()`, or as `final\nclass A`, would not put `T_FUNCTION` / `T_CLASS` on the reported line. PSR-12 and php-cs-fixer keep modifiers on the declaration line, and adding the modifier tokens would falsely flag property lines. So this is not a finding.
- If `git` fails, the test does not fail closed everywhere: see R2-2.

## New findings

### R2-1 (low): config-level bypasses through `ignoreErrors` messages and `excludePaths`

`tests/CognitiveComplexityGateTest.php:57-64` forbids only the literal `complexity.` and the regex `/cognitive complexity/i` in
`phpstan.neon.dist`. The rule messages are `Cognitive complexity for "%s" is %d, keep it under %d` and
`Class cognitive complexity is %d, keep it under %d`
(`vendor/tomasvotruba/cognitive-complexity/src/Rules/*CognitiveComplexityRule.php`). These entries each pass every assertion and each silence the rule:

- an entry `- message: '#keep it under \d+#'` in `ignoreErrors`, with `path:` scoped to one file
- an entry `- message: '#is \d+, keep#'` in `ignoreErrors`
- a `parameters.excludePaths` entry, which removes a file from analysis entirely

The existing `ignoreErrors` entries are only the two benign ones (`never returns string…`, `Call to method…`).

Suggested fix:

- pin `parameters → ignoreErrors` to an allowlist of the current `message:` values (the NEON reader can already extract them)
- assert that `parameters` has no `excludePaths` key, or that the top-level keys of `parameters` form a known set

Automated check: this test class. It is the same "gate bypass" class as R1-1, so per the review rules it belongs in this PR's gate rather than in another report.

### R2-2 (nit): `testNoOtherPhpstanConfigIsTracked` passes vacuously when `git` fails

`tests/CognitiveComplexityGateTest.php:267-276` returns `[]` when `shell_exec` yields `null`/`''`. This happens when `git` is missing, the directory is not a repository, or git refuses it with `safe.directory` (dubious ownership in a container). In those cases `testNoOtherPhpstanConfigIsTracked` (:66-71) passes without checking anything.

`testNoInlineIgnoreCanSuppressTheComplexityRule` is guarded by `assertNotEmpty($files)` (:90), and `LintScopeTest.php:35` guards its own `gitLsFiles()` the same way. Suggested fix:

- in `testNoOtherPhpstanConfigIsTracked`, first assert that `gitLsFiles([self::CONFIG])` returns `[self::CONFIG]`
- alternatively, also assert `!file_exists()` for the two names, which also covers the local file PHPStan would pick up

Automated check: the test itself.

### R2-3 (nit): the ignore in `PollingMonitorWatcherTest` could be a type instead

`tests/PollingMonitorWatcherTest.php:80-83`: the narrowed `@phpstan-ignore missingType.generics` is correct and is the minimal R1-1 fix. However, `@param \ReflectionClass<object> $class` would likely remove the need for an ignore at all, and the project prefers fixing a type over ignoring it. Optional; I have not verified it against PHPStan.

## Other observations (not findings)

- The coder edited `review-1.md` (a review-owned file) to remove a file name that `BinDirectoryTest:347` forbids in tracked Markdown. The meaning is unchanged and the edit is recorded in code-decision-2. Accepted. This round avoids the name too.
- Splitting the gate into its own `#[CoversNothing]` class is consistent with how the other lint-scope tests are organised.

## Candidate knowledge-base entry (for whoever merges)

- **Title:** a bare `@phpstan-ignore-next-line` / `-line` silences every error on the line, including the text after it
- **Tags:** phpstan, lint, complexity
- **Trigger:** a `@phpstan-ignore-next-line <identifier>` in a docblock
- **Paragraph:** PHPStan treats text after `-next-line` / `-line` as a comment, so the ignore is not scoped to the identifier. In a function's docblock it hides the cognitive-complexity error, which is reported on the declaration (or first attribute) line. Use `@phpstan-ignore <identifier>`. `CognitiveComplexityGateTest` fails when the old form sits on a declaration line.

## Verdict

**Not clean.** R1-1 and R1-2 are fixed. R2-1 (low) is open, and R2-2 and R2-3 are nits.
