# findings-coder.md — #654 (changelog structural validation in composer lint)

Appended by the coder subagent for issue #654. Each finding names a file/line
and a suggested fix; several are outside the issue's scope and were left
untouched on purpose.

## F-1. Global `main()` collision across bin scripts (latent fatal + PHPStan confusion)

`bin/pick-issue.php:447` declares `function main(array $options): void`,
`bin/kb-lint.php:642` `function main(array $options): int`, and now
`bin/check-changelog.php` declares one too. PHP function names are global:
including any two of these scripts in one process is a fatal
"Cannot redeclare main()". It also bit *this* task concretely — PHPStan
resolves `main(...)` calls across all analysed files, so my initial
`exit(main(...))` was type-checked against pick-issue's void signature and
failed with "Result of function main (void) is used" even though my own
signature returned int.

Suggested fix: give each script's bootstrap a unique name (`kbLintMain()`,
`pickIssueMain()`, …) or wrap each script in a namespaced
`namespace { ... }` block. Not done here to keep the diff to the issue's
scope; the new script at least follows the majority convention (void main,
internal exit) so behaviour is uniform.

## F-2. Scoped php-cs-fixer runs need an explicit --config

`vendor/bin/php-cs-fixer fix --dry-run -v <fileA> <fileB>` aborts with
"For multiple paths config parameter is required"
(PhpCsFixer\ConfigurationException\InvalidConfigurationException) because a
multi-path invocation disables auto-discovery of `.php-cs-fixer.dist.php`.
Single-path invocations work. Anyone scoping the linter to changed files (as
the review loop encourages) hits this with no hint in the error about the fix.
Suggested fix: either document `--config=.php-cs-fixer.dist.php` for scoped
runs in docs/workflow.md step 7, or add a `composer lint:files -- <paths>`
script that passes the config explicitly.

## F-3. docs/helpers/faq.md is over its own line budget (pre-existing)

`composer lint` warns: "docs/helpers/faq.md: 350 lines (index excluded) is
over the 300-line budget — promote or drop entries" (kb-lint LINE_BUDGET=300,
bin/kb-lint.php:68). The warning is advisory, but it has been printing on
every lint. Suggested fix: at the next retro, promote or merge FAQ entries
(FAQ-015 is already promoted; candidates exist among the date-time/http
clusters).

## F-4. ChangelogStructureTest duplicated rules that CI enforced only via PHPUnit (fixed by this issue)

Before this change the entire structural gate lived in
tests/ChangelogStructureTest.php, which runs only in the test matrix — the CI
Lint job (`composer validate --strict`, `composer audit`, `composer lint`)
never looked at markdown, exactly as issue #654 described. Now single-sourced
in bin/check-changelog.php. No action left; recorded because the
"test-only gate" pattern is easy to repeat: a PHPUnit test that encodes a repo
policy is not a lint gate until the policy is reachable from `composer lint`.

## F-5. Reference-format acceptance is looser than the visible convention

bin/check-changelog.php (validateChangelogLines, rule 4) accepts `[#123]`
anywhere in an entry's text — including wrapped continuation lines — and bare
`(#123)`. The rendered changelog convention is a trailing markdown link
`([#123](https://…))`. This matches the pre-refactor test deliberately (both
formats accepted, per the #686 rationale), but it means an entry whose only
reference is buried mid-sentence passes. Suggested fix (optional, needs a
decision): require the reference on the first line, or require the link form,
and update the frozen-list comment accordingly.

## F-6. check-changelog reports "no released version headings" for a fresh project

A CHANGELOG.md containing only `[Unreleased]` fails with "CHANGELOG.md has no
released version headings". That behaviour was inherited verbatim from the
pre-refactor test (assertNotEmpty on released headings), so it is not a
regression — but it will confuse anyone bootstrapping a new repo. Suggested
fix: downgrade to a warning once a `--strict` flag exists, or accept it and
document it in bin/README.md (currently documented only implicitly).

## F-7. Shell pipes mask exit codes (session observation, not a repo defect)

While gating this task, piping `vendor/bin/php-cs-fixer ... | head` reported
exit 0 even when cs-fixer exited 16 (see F-2). Any scripted gate that pipes
tool output must use `set -o pipefail` or capture `${PIPESTATUS[0]}`.
Worth remembering for CI step authoring; no repo change needed (CI steps run
tools unpiped).
