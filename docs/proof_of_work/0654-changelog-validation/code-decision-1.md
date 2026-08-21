# code-decision-1.md — structural CHANGELOG validation wired into composer lint (#654)

Scope: `bin/check-changelog.php` (new), `tests/ChangelogStructureTest.php`
(rewritten as a subprocess driver), `composer.json`, `bin/README.md`,
`docs/workflow.md`, `CHANGELOG.md`, plus this directory.

## The approach

**The validation logic moved, not copied.** The four rules (unique+first
`[Unreleased]`, well-formed strictly-descending released headings, unique
Keep-a-Changelog subheadings per block, references on every top-level entry
outside the frozen legacy list) lived as PHPUnit assertions in
`tests/ChangelogStructureTest.php` since #686/#687. They now live once, in
`bin/check-changelog.php`; the test drives the script as a subprocess exactly
the way `tests/KnowledgeBase/KbLintScriptTest.php` drives `bin/kb-lint.php`
(sandbox dirs under `sys_get_temp_dir()`, `proc_open` with stdout/stderr
captured to files, real changelog passes, synthetic fixtures fail). Duplicating
the rules in both places was rejected outright: the whole point of #654 is one
gate, and two implementations of "descending order" *will* drift. The frozen
`LEGACY_ENTRIES_WITHOUT_A_REFERENCE` list and its rationale comment moved
verbatim into the script header area.

**Wiring is one composer.json edit, by design.** Per DEC-008 ("composer lint /
lint-fix are the canonical entry points"), the new script was appended to the
`lint` array and given a standalone `changelog:check` alias — mirroring how
`kb-lint` appears both standalone and inside `lint`. `.github/workflows/tests.yaml`
and `bin/install-git-hook.php` were deliberately NOT edited; instead both were
verified by reading them to invoke `composer lint` (tests.yaml line 42:
`run: composer lint`; install-git-hook.php line 16: `composer lint || exit 1`),
so this single change reaches the CI Lint job and the pre-push hook. Editing
either file directly would have created a second wiring path to keep in sync.

**Root redirection copies kb-lint's mechanism, warnings included.**
`--root=DIR` wins over the `CHANGELOG_CHECK_ROOT` environment variable; using
the env var prints a visible warning and the resolved root is always printed,
because a structural gate whose target can be switched invisibly is not a gate.
This is what lets the PHPUnit tests run the script against sandboxes without
ever touching the real tree (the one passing case does read the real
CHANGELOG.md).

**Exit codes follow the two ancestors:** 0 = valid, 1 = violations found
(all violations collected and reported together, each with its line number),
2 = usage error (unknown option, nonexistent root, missing/unreadable
CHANGELOG.md — the latter mirrors `check-coverage.php` treating an unreadable
input as usage/environment error rather than a lint failure).

## Things I was unsure about

- **`main()` returning int vs void.** My first draft used `exit(main(...))`
  with `main(): int`. PHPStan failed it: "Result of function main (void) is
  used" — because `bin/pick-issue.php` also declares a global `function main()`
  (void) and PHPStan resolved my call against *that* declaration. Rather than
  inventing a differently-named bootstrap for one script, I matched the sibling
  convention (void `main()` that exits internally, like kb-lint.php and
  pick-issue.php). The underlying collision is recorded in findings-coder.md as
  a latent repo-wide hazard.
- **Missing CHANGELOG.md: exit 1 or 2?** kb-lint treats a missing knowledge-base
  file as a lint error (1); check-coverage treats an unreadable input as usage
  error (2). I chose 2 because the changelog path here is an *input* like the
  clover file, not a fixed part of the audited tree. Documented in the script
  header either way.
- **Kept `$releasedCount === 0` fails.** A changelog containing only
  `[Unreleased]` fails ("no released version headings") — that assertion existed
  in the pre-refactor test, so it moved verbatim even though a brand-new project
  would trip it. Changing it silently would have been a behaviour change hidden
  inside a refactor.
- **docs/workflow.md got a second, unrequested touch-up.** The task asked for
  step 7; the CI-job enumeration further down (job 1's parenthetical list of
  what `composer lint` covers) would have gone stale in the same commit, so it
  was updated too. Both are one-line comment/prose changes.
