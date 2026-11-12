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
