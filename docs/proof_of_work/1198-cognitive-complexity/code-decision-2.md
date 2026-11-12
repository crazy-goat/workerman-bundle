# #1198 stage 1: round 2 (coder-high)

Round 2 fixes the two open points of review round 1: R1-1 (low) and R1-2 (nit).

## What changed

- **New `tests/CognitiveComplexityGateTest.php`.** It is `#[CoversNothing]` and replaces the round-1 method in `tests/LintScopeTest.php`. The gate grew to 6 tests plus a 13-case provider, so it got its own class.
  - `testTheExtensionIsTheOnlyInclude`: the `includes:` block of `phpstan.neon.dist` must be exactly `[- vendor/tomasvotruba/cognitive-complexity/config/extension.neon]`. Any extra include, which could override `cognitive_complexity`, fails. (R1-1)
  - `testTheLimitsAreSetInTheCognitiveComplexityBlockAndOnlyGoDown`: reads `parameters → cognitive_complexity` structurally. The block must hold exactly the keys `function` and `class`, each an integer, with `function ≤ 50` and `class ≤ 150`. (R1-2)
  - `testTheConfigHasNoBaselineAndNoComplexityIgnore`: the round-1 assertions, unchanged.
  - `testNoOtherPhpstanConfigIsTracked`: `bin/lint.sh:60` runs `phpstan analyse` without `-c`. PHPStan then prefers `phpstan.neon` (and `phpstan.dist.neon`) over `phpstan.neon.dist`, so a tracked file with either name would bypass every assertion above. Both names must therefore be untracked. (R1-1, a bypass the review did not list)
  - `testThePackageIsPinnedToAnExactVersion`: the round-1 assertion.
  - `testNoInlineIgnoreCanSuppressTheComplexityRule`: covers every git-tracked `*.php` file under the `paths` listed in `phpstan.neon.dist`, read from the config, so a new analysed path is picked up automatically. It is a `token_get_all()` scan of `T_COMMENT` / `T_DOC_COMMENT` tokens only, in line with DEC-020, so string fixtures do not count. It fails on:
    1. any `@phpstan-ignore…` comment whose text names `complexity.`
    2. any `@phpstan-ignore-next-line` whose target line (the comment's end line + 1), or `@phpstan-ignore-line` whose own lines, hold a `function`, `class`, `interface`, `trait`, `enum` or `#[` token. `X::class` does not count.
  - `testTheIgnoreScanFlagsOnlySuppressingComments`: a data provider with 8 positive and 5 negative cases. It pins the detector, so the scan cannot pass by finding nothing.
- **`tests/PollingMonitorWatcherTest.php:81`.** `@phpstan-ignore-next-line missingType.generics` became `@phpstan-ignore missingType.generics`. The old form sat in the docblock of `findProperty()`. PHPStan ignores the text after `-next-line`, so it silenced every error on the declaration line, cognitive complexity included. I verified this: a scratch function with exactly that docblock is not reported at `function: 0`. The new form suppresses only `missingType.generics`, and PHPStan stays clean.
- **`CONTRIBUTING.md`.** The ratchet bullet now lists exactly what `CognitiveComplexityGateTest` enforces, and states which bare `-next-line` uses remain allowed (those on a statement). (R1-1, the docs overclaim)
- **`review-1.md` §2 (this directory).** I reworded two mentions of the review findings file name to "the review findings file". `tests/BinDirectoryTest.php:347` (`testNoTrackedDocMentionsTheRemovedProcessTooling`) forbids that file name in any tracked Markdown file, so the committed review-1 made `composer test` fail. The meaning is unchanged. See the findings.

## How the bare `-next-line` question was decided

I checked how PHPStan attributes the error, using a scratch file at `function: 0, class: 0`:

- The complexity error sits on the node's start line. That is the first attribute line when the function has attributes (`#[\Deprecated]` on line 2 gave `a.php:2`), and the `class` line for the class rule.
- All of these silence the error:
  - a bare `@phpstan-ignore-next-line` in the docblock
  - a `//` comment above the attribute
  - `@phpstan-ignore-line` on the declaration line
  - `-next-line` with trailing text
  - `@phpstan-ignore complexity.functionLike`

So PHPStan **cannot** tell a bare ignore from a complexity ignore, but the scan **can** tell whether one lands on a line where a complexity error would be reported. I forbid only those. A bare ignore on a statement, such as `tests/UtilsTest.php:156` above `new Utils();`, cannot hide a complexity error and stays allowed. That keeps the gate proportional: the only existing violation was the one in `PollingMonitorWatcherTest`, and its fix is an exact-identifier ignore.

## Mutation checks (each reverted afterwards)

| Tamper | Failing test |
|---|---|
| extra `- local.neon` include | `testTheExtensionIsTheOnlyInclude` |
| `function: 51` | `testTheLimitsAre…` |
| decoy `foo: function: 1` above the block plus `function: 99` (the round-1 regex would have read 1) | `testTheLimitsAre…` |
| extra key `other: 1` in the block | `testTheLimitsAre…` |
| the old `PollingMonitorWatcherTest` docblock restored | `testNoInlineIgnore…` |
| `/** @phpstan-ignore complexity.functionLike */` added above `ExcludePattern::matches()` | `testNoInlineIgnore…` |
| clean tree | all pass |

## Alternatives rejected

- **Parsing NEON with a real parser.** `nette/neon` is not a direct dependency. It ships only inside `phpstan.phar` under a scoped namespace that changes per release, so a test depending on it would break on a PHPStan bump. Symfony YAML would reject NEON syntax such as tab indentation and `%param%`. A 30-line indentation-block reader covers the three blocks the test needs. It fails closed: an inline form such as `cognitive_complexity: {function: 50}` yields no entries, and the test fails.
- **Forbidding every bare `@phpstan-ignore-next-line`.** That is stricter than needed, and it would churn unrelated tests (`tests/UtilsTest.php:156`) for no gain against the complexity rule.
- **Running PHPStan from the test** with a scratch `function: 0` config and checking that no complexity error is "ignored". That is far slower, it duplicates lint, and PHPStan does not report which ignore swallowed what.
- **Only narrowing the CONTRIBUTING wording.** The review allowed this. A gate is preferred over prose, as `docs/helpers/README.md` says.

## What I am unsure about

- **`@phpstan-ignore <identifier>` placed on a declaration line with a non-complexity identifier is allowed.** PHPStan 2 applies identifiers strictly, so it cannot hide `complexity.*`. If a future PHPStan treated unknown text loosely, this would change. Low risk.
- **The scan matches `-next-line` / `-line` by substring anywhere in the comment.** A comment that only *mentions* the directive in prose would be flagged. The narrative-comment rule removes such comments anyway.
- **`T_ATTRIBUTE` on the target line also flags a bare ignore above a property attribute.** No such case exists today. This is a deliberate over-approximation.
- **Untracked local `phpstan.neon` files** are not checked. CI only sees tracked files, so the test covers the gate where it is enforced.
