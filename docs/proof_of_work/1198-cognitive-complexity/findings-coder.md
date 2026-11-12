# Findings: #1198 (coder)

## Round 1 (stage 1: 50 / 150), coder-high

### Biggest problem: proving that three rewrites behave identically, one of them in a security guard

Two of the three functions had no direct unit coverage of their internal branches:

- `ExcludePattern::guardAgainstNestedUnboundedQuantifiers()` was covered by 8 rejection cases only.
- `checkUpgradeExceptionsDeclaredTypes()` is reached only through the CLI.

A plain "the tests still pass" would not have proven byte-identical behaviour. I did three things:

- Added characterisation tests to `ExcludePatternTest` before the refactor: 12 more rejections, 16 acceptances, and the exact rejection message.
- Built throwaway differential harnesses that compare the original code with the new code. Details and numbers are in `code-decision-1.md`:
  - 2 × 300k fuzzed patterns for the guard
  - 1,803 token-scanned declarations across `src/`, `tests/` and parts of `vendor/`
  - 21 knowledge-base roots × 5 modes for `kb-lint`
- Re-ran all three harnesses after `lint-fix` rewrote the code. Every run showed zero differences.

A second, smaller obstacle: `composer lint-fix` needed **two passes**. The first pass of Rector (`LocallyCalledStaticMethodToNonStaticRector`) changed only some of the static helpers. Every caller is converted in the same pass, so after the first pass `composer lint` still failed on the remaining ones. The second `lint-fix` converged.

### Discovered bugs and weak spots (outside this issue's scope, not fixed here)

1. **ReDoS guard bypass through lookbehind.** `src/Phar/ExcludePattern.php:124`, `openGroup()`: the `(?<` branch, which existed before the refactor.
   - Problem: `(?<` is treated as the start of a named group and the scanner jumps to the next `>`. A lookbehind (`(?<=`, `(?<!`) has no `>`, so the scanner jumps to the end of the pattern and never inspects the rest.
   - Effect: `(?<=a)(b+)+` and `(?<!a)(b+)+` are both accepted (verified). A pattern such as `(?<=x)y(?<n>z)(a+)+` skips only up to the `>`.
   - Mitigation: the backtrack and recursion limits around `matches()` still prevent a hang. However, `matches()` returns `false` on a PCRE error, so a pathological pattern silently stops excluding files instead of failing the build.
   - Suggested fix: in `openGroup()`, when `$marker === '<'` and the next byte is `=` or `!`, return `$open + 4` (a lookbehind body is scanned like a group). Only search for `>` for a real `(?<name>`. Add `(?<=a)(b+)+` to `provideGuardRejections`. This needs its own issue, because it changes which patterns are accepted.

2. **The rejection message cites `(a|a)+` as an example, but the guard accepts it.** `src/Phar/ExcludePattern.php:191`.
   - Problem: the guard only detects a quantified group that contains a quantifier. Alternation overlap (`(a|a)+`, `(a|aa)+b`) is not detected, and both are accepted (verified).
   - Suggested fix: drop `"(a|a)+"` from the message, or document in `docs/build-packaging.md` and `docs/security.md` that alternation overlap is not caught. The second option builds on #1196, which already corrected the description. This changes the message, so it is a behaviour change: open a separate issue.

3. **`lint-fix` does not converge in one pass.**
   - Where: `bin/lint.sh:46` runs `vendor/bin/rector process` once, and `bin/lint.sh:61` runs the dry run.
   - Problem: `composer lint-fix` followed by `composer lint` failed on Rector until I ran `lint-fix` a second time.
   - Suggested fix: in `--fix` mode, run `rector process` until a `--dry-run` reports no changes (bounded, for example 3 iterations).

4. **`@phpstan-type Entry` in `bin/kb-lint.php:36` is never used.**
   - Problem: it is a free-standing docblock that is not attached to a class, so PHPStan ignores it. Every function repeats the full `array{file: …, body: list<string>}` shape. This refactor added 4 more copies.
   - Suggested fix: move the script into a small class, or keep a free function file with an `@phpstan-type` on a class-like holder. A cheaper alternative: accept the repetition and delete the dead docblock.

5. **`composer.json:86` lists `phpstan/extension-installer` in `allow-plugins`, but the package is not installed.** This is harmless, but it is easy to misread as "extensions auto-load". That misreading is why the issue had to call out the explicit include. Suggested fix: remove the `allow-plugins` entry, or install the plugin. If the plugin is installed, the explicit include must be removed to avoid a double include. `LintScopeTest` currently requires the explicit include.

6. **Stage-4 candidates where a documented decision constrains the refactor.**
   - `RequestConverter::processFileNode()` (22) is bound by DEC-019: single traversal, validate-while-converting.
   - `SfxDownloader`, with a class score of exactly 150, sits on the current class limit. Any new branch in that class fails lint until the class is split.

### Proposed knowledge-base candidates (for the merging maintainer)

- **FAQ candidate: "Cognitive complexity: closures count into the enclosing method; the limit is inclusive"**
  - Tags: `phpstan`, `lint`
  - Trigger: "PHPStan reports `complexity.functionLike`, or you are about to raise or ignore the limit"
  - Body: `tomasvotruba/cognitive-complexity` is loaded by an explicit include in `phpstan.neon.dist`, because there is no extension-installer. A score equal to the limit passes. Closures and arrow functions add to the enclosing function's score, so extracting a closure does not help; extract a named method or function. The limits only go down, and `LintScopeTest` fails on a baseline, a `complexity.` ignore or a raised limit. To measure lower thresholds, use a temporary config that `includes` `phpstan.neon.dist` and overrides `cognitive_complexity`; never commit it.
  - Prefer a gate: `LintScopeTest` is already the gate, so this entry may be unnecessary.
- **FAQ candidate: "`composer lint-fix` may need a second run when Rector converts statics"**
  - Tags: `lint`
  - Trigger: "`composer lint` fails on Rector right after `lint-fix`"
  - Prefer fixing `bin/lint.sh` (finding 3) over adding an entry.

## Round 2 (review R1-1, R1-2), coder-high

### Biggest problem: deciding which bare ignores can hide the rule, without forbidding them everywhere

PHPStan ignores the text after `@phpstan-ignore-next-line` and `@phpstan-ignore-line`. A bare ignore therefore cannot be told apart from a complexity ignore by reading the comment. I solved this by checking where a complexity error is reported, with a scratch `function: 0` run: the node's start line, which is the first attribute line if there is one, otherwise the `function` or `class` line. The scan then flags a bare ignore only when its target line holds such a token. This found one real hole in the tree, `tests/PollingMonitorWatcherTest.php:81`. A `@phpstan-ignore-next-line missingType.generics` docblock there silenced *every* error on the `findProperty()` declaration line, cognitive complexity included. It is now an exact `@phpstan-ignore missingType.generics`.

### Discovered bugs and weak spots

1. **A committed review file broke `composer test`.**
   - Where: `tests/BinDirectoryTest.php:347`, `testNoTrackedDocMentionsTheRemovedProcessTooling`.
   - Problem: the test forbids the literal names of the coder and review findings files, and the proof-of-work directory name, in every tracked `*.md` except `CHANGELOG.md`. The workflow (`docs/workflow.md` step 3/4 and the agent prompts) tells agents to write exactly those file names inside the proof-of-work directory. Review round 1 named the review findings file in §2, so the full suite failed on this branch. I reworded the two mentions; the meaning is unchanged.
   - Suggested fix: exempt the proof-of-work directory in that test, or say in the review and coder prompts that file names must not be spelled out in proof-of-work Markdown. The first is the safer choice, because the process tells agents to produce these files.
2. **`bin/lint.sh:60` runs `phpstan analyse` without `-c phpstan.neon.dist`.**
   - Problem: an untracked local `phpstan.neon` silently replaces the project config, gate and level included, for that developer's lint and pre-push hook. CI is safe, because the new test forbids a tracked file with that name.
   - Suggested fix: pass `-c phpstan.neon.dist` explicitly in `bin/lint.sh`.
3. **The other bare ignore, `tests/UtilsTest.php:156`** (`/** @phpstan-ignore-next-line */` above `new Utils();`), silences every error on that statement.
   - It is harmless for complexity, but it is broader than needed.
   - Suggested fix: `@phpstan-ignore new.private` or the exact identifier PHPStan reports.

### Knowledge-base candidate

- **FAQ: "`@phpstan-ignore-next-line <text>` ignores everything on the next line; the text is not an identifier"**
  - Tags: `phpstan`, `lint`
  - Trigger: "writing or reviewing a PHPStan ignore comment"
  - Body: only `@phpstan-ignore id1, id2` restricts the ignore to identifiers. `-next-line` and `-line` treat trailing text as a comment and silence every error on the target line, including errors from rules added later, such as `complexity.functionLike`. `CognitiveComplexityGateTest` flags them on declaration and attribute lines. Elsewhere, prefer the identifier form.
  - Prefer a gate: a php-cs-fixer or test rule that rejects `-next-line` / `-line` with trailing text would make this entry unnecessary.

## Round 3 (stage 2: function limit 40), coder-high

### Biggest problem: a pure refactor of worker-process callbacks whose tests did not reach every branch

The `ServerWorker` constructor (48) is almost entirely nested closures that run later, inside a Workerman child process: `onConnect` → `errorHandler`, `onWorkerStart` → the sweeper timer → the per-connection loop, and `onMessage`. Moving them into methods risks three things:

- a changed `$this` binding (static versus bound)
- changed captured variables, which `testOnConnectClosureCapturesBodySizeCap` inspects through reflection
- a changed order of side effects in `onWorkerStart`

A Clover run of `ServerWorkerTest` on the **original** class showed three statements that were never executed: the body-cap assignment, and the sweeper's two skip branches. I added and strengthened tests for them first, ran them against the original, and then refactored. I kept every closure literal unchanged and only moved where it is created. First-class callables were rejected because they would change `getStaticVariables()`.

### Discovered bugs and weak spots

1. **A vacuous test, fixed in this PR as characterisation.** At `tests/ServerWorkerTest.php:936`, `testOnConnectSetsBodySizeCap` asserted only that `onConnect` was set, never that the cap reached the connection. It now calls `onConnect` and asserts `maxPackageSize === 8192`.
2. **`onClose` is a non-static closure that never uses `$this`.** At `src/Worker/ServerWorker.php:76`, the same applies to `errorHandler`, `connectHandler`'s closure and `messageHandler`'s closure. They bind the `ServerWorker` for no reason.
   - This is harmless today, because `onWorkerStart` and `onWorkerReload` need `$this` anyway.
   - Suggested fix: make the closures that do not use `$this` `static`, in a separate change. It changes closure binding, so it is not a pure refactor, and that is why it is not done here.
3. **`assert(is_string($listen))` in `ServerWorker::__construct()`** (`src/Worker/ServerWorker.php:61`) is a no-op in production (`zend.assertions=-1`).
   - `ServerConfig['listen']` is `string|null`, and `?? ''` already makes it a string, so the assert is dead code.
   - Suggested fix: remove it, or tighten the phpstan-type.
4. **The old `parseFile()` used a by-reference alias into `$entries`, plus a dead `$current` variable.** This was a latent hazard: any later `$entries[] = …` copy-on-write while the reference was alive would have been subtle. It was replaced by an integer index, and the dead variable was removed. This is noted only for the record.
5. **Stage-3 note.** `GithubWorkflowsTest::testMatrixRewriteExclusionsCoverOffSchemeSymfonyPackages()` (36) is a test method. Splitting it means extracting assertion helpers, which is a natural split.

### Knowledge-base candidate

- **FAQ: "Extracting a closure into a method: return the closure literal, don't switch to a first-class callable"**
  - Tags: `phpstan`, `closures`, `tests`
  - Trigger: "refactoring a callback-heavy constructor such as ServerWorker or another worker for the cognitive-complexity limit"
  - Body: a method that returns the same `function () use (...)` literal keeps the `$this` binding, the static/non-static kind and the captured variable names. Tests such as `testOnConnectClosureCapturesBodySizeCap` read those names through `ReflectionFunction::getStaticVariables()`. `$this->method(...)` changes all three.
  - Prefer a gate: the reflection test is already the gate for `onConnect`, so this entry is optional.

## Round 4 (stage 3: function limit 30), coder-high

### Biggest problem: proving that a refactored test still fails under the same conditions

A refactored test that passes proves little: a helper that silently skips an assertion still passes on a clean repository. For `GithubWorkflowsTest::testMatrixRewriteExclusionsCoverOffSchemeSymfonyPackages()` I built 10 scratch roots: the clean workflow plus composer.json, and 9 mutations, one per guarded rule. I ran the old and the new test file on each. Both pass on the clean root, and both fail on every mutation with the same first message. This is recorded in `code-decision-4.md`. The same concern applied to the tree parser's error branches in `bin/check-upgrade-exceptions.php`, which no test reached. The three new tests were checked against mutants that delete each guarded branch.

### Discovered bugs and weak spots (not fixed: behaviour changes)

1. **The named-type scan never removes the hierarchy tree block.** In `bin/check-upgrade-exceptions.php`, `checkUpgradeExceptionsNamedTypeScope()` (`bin/check-upgrade-exceptions.php:225` and `:233`; the logic predates this PR, at former lines 174/178):
   - Problem: `preg_match` (not `preg_match_all`) fills `$blocks[0]` with the whole match as a string, so `$blocks[0][0]` is its first character, a backtick. The function removes one backtick at the first backtick after the marker, not the tree block.
   - Effect: harmless today, because tree lines carry no backtick spans and no `use` / `catch` / `new` text. But the intended exclusion does not happen.
   - Suggested fix: use `$blocks[0]` in both places. Add a test where the tree contains `` `GhostException` `` inside the text block and must not be reported as named outside the tree.
2. **`GithubWorkflowsTest` coerces numeric constraint keys.** `array_count_values()` turns a numeric-string constraint into an int key, and the old test compared it with `===` against strings. The helper now casts the key to `string`. This is not reachable with real Symfony constraints. It is noted in `code-decision-4.md`.
3. **Stage-4 note.** `bin/check-exception-usage.php` holds two of the remaining stage-4 functions (29 and 28). It mirrors the token-scan structure that `check-upgrade-exceptions.php` had before stage 1, so the stage-1 split of `checkUpgradeExceptionsDeclaredTypes()` is a ready template. The two scripts could share the token reader, but DEC-008 (one entry point per check) should be checked first.

### Knowledge-base candidate

- **FAQ: "Refactoring a test: prove the failure conditions with a mutation run, not a green run"**
  - Tags: `tests`, `phpstan`
  - Trigger: "splitting a long test method for the cognitive-complexity limit"
  - Body: run the old and the new test against scratch copies of the guarded input (workflow YAML, composer.json, fixtures), one mutation per assertion, and compare that both fail with the same message. A green run on the real repository only proves that nothing new fails.
  - Prefer a gate: none practical, so this is process guidance. It is optional.
