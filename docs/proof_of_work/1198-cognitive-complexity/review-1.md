# Review round 1: #1198 stage 1 (cognitive-complexity gate at 50 / 150)

Reviewer: `review-critical`. Branch `refactor/issue-1198-cognitive-complexity-50`, diff `origin/master...HEAD` (5 commits, 13 files, +712 / −257).

## 0. Why the critical review is required

- The diff has **more than 200 changed lines**: +712 / −257.
- It is **security-relevant**: `src/Phar/ExcludePattern.php` holds the ReDoS guard for the `exclude_patterns` used in the PHAR build. See `docs/security.md`.
- It does not touch `src/Http`, process supervision or a public interface. `ExcludePattern` is `@internal`.

## 1. Knowledge base (`docs/helpers/`, read through the tag index)

I read the entries tagged `lint`, `phpstan`, `bin`, `tests` and `coverage`/`policy`: FAQ-014, FAQ-015, FAQ-029, FAQ-031, FAQ-032, FAQ-037, DEC-007, DEC-008, DEC-020 and DEC-021.

**No violations.**

- **DEC-007, ratchet only.** The cap behaves like the coverage floor and can only go down: one number, no baseline, and a test that refuses to let it go up.
- **DEC-008.** The new check runs inside PHPStan, which `bin/lint.sh` already runs. No new entry point was added.
- **DEC-020.** `check-upgrade-exceptions.php` still discovers types with `token_get_all()`. It does not use a regex.
- **FAQ-031.** `bin/` is in the analysed paths, so the cap covers the two `bin` scripts.
- **DEC-021.** The CHANGELOG entry is under `[Unreleased]`, and `bin/check-changelog.php` passes.
- **FAQ-032.** No workflow YAML changed, so it does not apply.

## 2. Earlier findings (`findings-review.md`)

This is the first round, so `findings-review.md` did not exist and there was nothing open to re-check.

## 3. Verification

### `composer lint` and `composer test`

- **Lint.** `LINT_SKIP_AUDIT=1 composer lint` passes (exit 0) and reports "All checks passed.". That covers php-cs-fixer with the no-narrative-comments rule, PHPStan level 8 with the new extension, Rector, kb-lint, check-changelog, shellcheck and hadolint.
- **Tests.** `composer test` ran 3015 tests with 20011 assertions. There was **one failure**, `RestartBackoffTest::testStopSignalEndsTheWaitEarly`, which is the known host failure from FAQ-007 (macOS / grpc, signal 9 instead of a clean exit). 47 tests were skipped. `ExcludePatternTest`, `LintScopeTest`, `UpgradeExceptionsLintTest` and `tests/KnowledgeBase/*` all pass.

### `ExcludePattern`: independent differential check

I extracted the original class with `git show origin/master:src/Phar/ExcludePattern.php` into the `RcOld\ExcludePatternOld` namespace under `/tmp`. For every pattern I compared `ok` plus the `matches()` result, or the exception class plus the full message.

- **Random alphabet**, with metacharacters and the group, brace and escape tokens: 3 seeds × 150k patterns, 25% of them wrapped in delimiters. **0 differences.**
- **Grammar-based generator**, 3 seeds × 150k patterns, nested up to depth 4:
  - groups: `(`, `(?:`, `(?=`, `(?!`, `(?<n>`, `(?<=`, `(?<!`, `(?>`, `(?i)`, `(?i:`, `(?P<x>`, `(?#c)`, and unclosed groups
  - character classes: `[]a]`, `[\]+]`, `[(]`, `[\\]`, and an unterminated `[`
  - escapes: `\(`, `\\`, `\Q+\E`
  - quantifiers: `+ * ? {2} {2,} {1,3} +? *+ ?? { {,} }`
  - a strict error handler that turns every unsuppressed notice or warning into a difference

  This gave **0 differences** across 450k patterns: about 111k nested-quantifier rejections, 196k acceptances and 143k invalid-PCRE rejections. No new notices or warnings appeared, for example from the new `?? ''` string-offset reads.
- **Reasoning about the code** agrees with the fuzz:
  - `$inner[$x] ?? ''` gives `''` past the end, which matches the old `$x < $length &&` guards.
  - `str_contains(substr($inner, $close + 2), '}')` is equivalent to `strpos($inner, '}', $close + 2) !== false`, including at offset == length.
  - The `(?<` `strpos` offset is still at most `$length`.
  - `array_map` keeps the list keys.
  - `match` is strict, like the old `===` and `in_array(..., true)`.
- **The two known bugs are unchanged, not introduced.** In both the old and the new class, `(?<=a)(b+)+`, `(?<!a)(b+)+`, `(a|a)+` and `(a|aa)+b` are accepted, and `\Q(a+)+\E`, `(a++)+` and `(?#x)(a+)+` are rejected (the guard over-rejects these, as before).

### `bin/check-upgrade-exceptions.php`

- **The CLI** with `--root=<repo>` gives identical stdout and exit code for the old and the new script.
- **`checkUpgradeExceptionsDeclaredTypes()`** returns an identical `md5(serialize())` for both versions. The input was `src/Exception`, `src`, `tests`, `tools`, `bin`, `benchmarks`, `vendor/{symfony,phpunit,phpstan,nikic,workerman,rector,tomasvotruba}`, which is **5456 declarations**.
- **Reading the code**, I traced each index-returning path against the original:
  - name not found because of a non-array token: `$i` is unchanged
  - name not found because the token is not `T_STRING`: `$i = $j`
  - heritage that stops at `{` or `;`, at the next type keyword (`$j - 1`), or at the end of the tokens (`$nameAt`)
  - the per-file merge keeps the old overwrite and insertion-order semantics

  All of them match the original.

### `bin/kb-lint.php`

I ran the old and the new script on 7 knowledge-base roots: clean, index out of sync, no index, duplicate id, stale entry plus a missing trailing newline, missing file, and bad front matter. Each root ran in 4 modes: text, `--json`, `--fix` and `--fix --json`. I compared stdout, stderr, exit codes and the trees left after `--fix` with `diff -r`. I also ran the clean root through `KB_LINT_ROOT`.

**0 differences.** The error and warning order is the same as before: parse, entry and index per file; then root-env, budget, index-fix and duplicates.

### The gate

- **Wiring.**
  - The `includes:` line points at `vendor/tomasvotruba/cognitive-complexity/config/extension.neon`.
  - `parameters.cognitive_complexity` is set to `function: 50` and `class: 150`. The extension's `parametersSchema` accepts exactly these keys.
  - `composer.json` pins `1.3.0`, the same way as phpstan and rector.
  - The lock file adds only that package. It requires `phpstan/phpstan ^2.0` (pinned at 2.3.1) and `nikic/php-parser ^5.3`, so it adds no new transitive packages.
- **The extension is really active.** I ran a scratch config with `function: 1`, and it reports `complexity.functionLike`.
- **What `LintScopeTest::testCognitiveComplexityGateIsWiredAndOnlyRatchetsDown` catches:**
  - the include missing or commented out (`#` breaks the regex)
  - a limit above 50 or 150
  - any `baseline`
  - a `complexity.` identifier ignore
  - a `Cognitive complexity` message ignore
  - a loosened pin
- **What it does not catch:** see R1-1 and R1-2.
- **Docs.**
  - `CONTRIBUTING.md` documents the limits, that the limit is inclusive, the closure rule, the paths and the ratchet.
  - `AGENTS.md` mentions the cap on the PHPStan line.
  - The `CHANGELOG.md` entry is under `[Unreleased] → Changed` with `(#1198)`.
  - All of these are accurate except the overclaim noted in R1-1.
- **No narrative comments were added.** The fixer passes, and the new docblocks contain only `@param`, `@param-out`, `@return` and `@throws`.
- **No gate was lowered.** The PHPStan level stays at 8, the existing `ignoreErrors` are unchanged, and the coverage floor is untouched.

## 4. New findings

### R1-1 (low): the "no complexity ignore" gate only covers `phpstan.neon.dist`, while CONTRIBUTING implies it covers everything

**Where:** `tests/LintScopeTest.php:32-53` and `CONTRIBUTING.md:324-326`.

**What I verified.** I used a scratch config with `function: 1`. A `/** @phpstan-ignore complexity.functionLike */` docblock on a function silences that function's complexity error, while the function next to it without the docblock is reported. The `CrazyGoat/no_narrative_comments` fixer deliberately keeps `@phpstan-ignore*` directives, so nothing stops such a docblock.

**Other ways around the gate.**
- A bare `@phpstan-ignore-next-line` above a function silences the error too.
- So does a second `includes:` entry pointing at a local neon file that overrides `cognitive_complexity`. The include regex explicitly allows extra includes.

**Why it matters.** `CONTRIBUTING.md` says "there is no baseline and no `complexity.*` ignore, and `tests/LintScopeTest.php` fails if either appears". That reads as a whole-repository guarantee, but the test only reads the neon file.

**Not a merge blocker.** The issue only asks for "a test that pins that `phpstan.neon.dist` … has no baseline / complexity ignore", and that is met.

**Suggested fix, in this PR or as stage-2 hardening:**
- Extend the test to scan the tracked `*.php` files under the analysed paths for `@phpstan-ignore[^\n]*complexity\.` and fail on any match. Exclude string fixtures such as `tests/Tools/NoNarrativeCommentsFixerTest.php`, or match only real comment tokens through `token_get_all()`, in line with DEC-020.
- Assert that `includes:` contains only the extension.
- Alternatively, narrow the CONTRIBUTING wording to "in `phpstan.neon.dist`".

**Which check could catch it:** the test scan described above.

### R1-2 (nit): the limit regexes are not tied to the `cognitive_complexity` block

**Where:** `tests/LintScopeTest.php:42-43`.

**What is wrong.** `/^\s+function:\s*(\d+)\s*$/m` and `/^\s+class:\s*(\d+)\s*$/m` take the first `function:` or `class:` key anywhere in the file. Today these keys are unique. If another integer `function:` or `class:` key ever appeared above the block, the assertion would read the wrong value.

**Suggested fix.** Anchor the match to the block, for example `/^\s+cognitive_complexity:\s*\n\s+function:\s*(\d+)\s*\n\s+class:\s*(\d+)/m`. Or parse the file with the `nette/neon` that PHPStan bundles (`vendor/phpstan/phpstan` ships it inside the phar, so this may not be practical), or with a small line-based scan of the block.

## 5. Other observations, not findings

- **Coder's findings 1 and 2** (the lookbehind bypass, and the `(a|a)+` message): I confirmed both bugs exist on `origin/master` and are unchanged by this PR. They need their own issue, as the coder proposes.
- **Coder's finding 3** (`lint-fix` does not converge in one pass): plausible. I did not reproduce it, because the branch is already converged and `composer lint` is clean.
- **`provideGuardAcceptances` pins the current acceptance of `(?<=a)b`.** That stays valid if the lookbehind bug is fixed later, because the pattern has no nested quantifier.
- **`SfxDownloader` sits exactly at the class limit of 150.** That is legal because the limit is inclusive, but any added branch in that class will fail lint until the class is split. This is noted for stage 4.

## 6. Knowledge-base candidates (for the merging maintainer)

- I endorse the coder's candidate **"Cognitive complexity: closures count into the enclosing method; the limit is inclusive"** (tags `phpstan`, `lint`). Add one sentence to it: an inline `@phpstan-ignore complexity.*` or `@phpstan-ignore-next-line` on a function does suppress the rule, so it is forbidden by policy, not by a gate (unless R1-1 adds the scan). If R1-1 is fixed with a gate, prefer the gate and drop the entry.

## Verdict

**Round 1 has no blocking findings.**

- 0 high, 0 medium.
- 1 low (R1-1, a gap in the gate's coverage plus a docs overclaim).
- 1 nit (R1-2).

All three refactors are behaviour-identical. I checked that independently with differential runs: 900k patterns for `ExcludePattern`, 5456 declarations for `check-upgrade-exceptions`, and 7 roots × 4 modes for `kb-lint`. Lint is green. The tests are green except for the known FAQ-007 host failure.

Fixing R1-1 is recommended but optional for this stage. It can also be folded into the stage-2 PR.
