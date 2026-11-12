# Findings: #1198 (review)

| Round | File:line | What is wrong | Severity | What happened to it |
|---|---|---|---|---|
| 1 | `tests/LintScopeTest.php:32-53`, `CONTRIBUTING.md:324-326` | R1-1: the gate checks only `phpstan.neon.dist`. An inline `@phpstan-ignore complexity.functionLike` (verified to suppress the rule), a bare `@phpstan-ignore-next-line`, or an extra `includes:` file that overrides `cognitive_complexity` all bypass it. CONTRIBUTING says `LintScopeTest` "fails if either appears", which reads as covering the whole repository. | low | open, round 1. Suggested fix: a token-based scan of the analysed PHP for `@phpstan-ignore…complexity.`, plus an assertion that `includes:` holds only the extension; or narrow the docs wording. |
| 1 | `tests/LintScopeTest.php:42-43` | R1-2: the `function:` and `class:` limit regexes take the first such key anywhere in the file, not the keys inside the `cognitive_complexity:` block. | nit | open, round 1. Suggested fix: anchor the regex to the block. |
