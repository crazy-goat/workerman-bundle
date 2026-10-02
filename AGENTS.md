# AGENTS.md

Project commands and specifics for workerman-bundle, a Symfony bundle that runs an application
on Workerman (HTTP server, task scheduler, process supervisor, PHAR packaging). The
development process (issue, worktree, review, PR, merge, findings) is in
[docs/workflow.md](docs/workflow.md), the release process in
[docs/release-workflow.md](docs/release-workflow.md). The default branch is `master`.

Everything is written in English (code, comments, docs, commits, issues). UTF-8 test data
in `tests/RequestConverterTest.php` is the only exception.

## Layout

| Path | Content |
|---|---|
| `src/` | Bundle, namespace `CrazyGoat\WorkermanBundle\` |
| `tests/` | PHPUnit tests; most sit flat in `tests/`, named after the class. `tests/App/` is the test application |
| `benchmarks/` | PHPBench subjects (`composer bench`) |
| `e2e/` | Minimal Symfony app used by the PHAR/binary build tests |
| `bin/` | Lint script, worktree scripts, `pick-issue.sh`, project tools (see [bin/README.md](bin/README.md)) |
| `docs/` | Product docs (`build-packaging.md`, `security.md`, `troubleshooting.md`), process docs, `helpers/` knowledge base |
| `.pi/agents/` | Prompts of the coder and review subagents |
| `UPGRADE.md` | Breaking changes and deprecations; see the release section below |

## Commands

PHP 8.2+ with `pcntl`, `posix` (and `inotify`, `zip`, a coverage driver for coverage) and Composer.

```bash
composer install

composer lint            # runs bin/lint.sh (check only)
composer lint-fix        # runs bin/lint.sh --fix, then checks again
composer test            # starts a Workerman daemon, runs PHPUnit, stops the daemon
composer test:coverage   # same, with a Clover report in var/coverage.xml
composer coverage:check  # line-coverage floor (80%, defined once in composer.json)
composer bench           # PHPBench
```

`bin/lint.sh` runs `composer validate --strict`, `composer audit` (skipped when
`LINT_SKIP_AUDIT=1`, which the pre-push hook sets: audit needs the network and a new advisory
would block every push; CI always audits), PHP-CS-Fixer (dry run),
PHPStan (**level 8**), Rector (dry run), `bin/kb-lint.php`, `bin/check-changelog.php`,
`bin/check-exception-usage.php`, `shellcheck` on every shell script (including extensionless
ones such as `bin/docker-test`) and `hadolint` on the Dockerfile. It runs every step and fails
if any step failed. `shellcheck` and `hadolint` must be installed; a missing tool is a failure.
`bin/pick-issue.sh`, `bin/worktree.sh` and `bin/worktree-done.sh` are byte-identical copies of
the shared scripts: do not edit them.

Run `composer lint-fix` and then `composer lint` before committing. Push only when
`composer lint` and `composer test` pass.

**Lowering a gate is never an option.** Dropping the coverage floor, disabling a linter rule
or relaxing the PHPStan level to make a check pass is forbidden; report the conflict instead.

## Tests and ports

- `composer test` boots a real Workerman daemon on ports **8888**, **9999** and **9991**
  (hard-coded in `tests/App/Kernel.php`; only the listen address can be set, with
  `WMB_LISTEN_ADDR`). "Address already in use" means a stale daemon: `php tests/App/index.php stop`.
  On slow hosts raise `COMPOSER_PROCESS_TIMEOUT`.
- Because of the fixed ports, two worktrees cannot run `composer test` on the same host at the
  same time; run the suites one after another with `composer test` on the host (recommended).
  `bin/docker-test-worktree <path>` is meant to isolate parallel runs but is known to be broken
  for real git worktrees (the `.git` file points to a host path), so do not rely on it.
  `bin/docker-test` works for a normal checkout. Lint does not run in the image (it has no `shellcheck`/`hadolint`); run
  `bin/lint.sh` on the host. Details: [CONTRIBUTING.md](CONTRIBUTING.md).
- `UtilsTest` signal tests need `pcntl` and `posix`; without them set
  `WORKERMAN_ALLOW_PCNTL_SKIP=1` (CI never skips them).
- `bin/` scripts have PHPUnit coverage (`tests/BinDirectoryTest.php`,
  `tests/CoverageCiGateTest.php`, `tests/KnowledgeBase/`): run `composer test` after touching them.

## Worktrees

`bin/worktree.sh <issue>` runs `bin/worktree-setup.sh`, which runs `composer install`
(this also installs the shared pre-push hook) and creates `var/`. The repository has no compose file, no container is started
and no host port is published. If you add one, use `"${NAME_PORT:-N}:N"` and no
`container_name`, so that worktrees can run side by side.

## Knowledge base (`docs/helpers/`)

`docs/helpers/faq.md` and `docs/helpers/decisions.md` hold recurring pitfalls (`FAQ-NNN`) and
project decisions (`DEC-NNN`). Both open with a generated **tag index**: load the index, pick
the tags that match your diff, and read only those `###` entries. Never read a file end to end.
A documented decision is binding: if a change contradicts one, stop and report it.

Subagents do not edit `docs/helpers/`. They propose candidate entries (title, tags, trigger,
one paragraph) in their report or in `findings.md`; the entry is written by the person who
merges, in the pull request. Prefer a gate (test, linter rule) over an entry. Format, decay
rules and the linter: [docs/helpers/README.md](docs/helpers/README.md).

## Agents

The subagent prompts live in `.pi/agents/`. They implement the roles of the workflow:

| Role | Agent |
|---|---|
| Coder (workflow step 3) | `coder`; `coder-high` for multi-file or risky changes |
| Review (step 4) | `review`; `review-critical` is mandatory for diffs touching `src/Http`, security, process supervision, a public interface, or more than 200 changed lines |
| Recon, claims, candidate findings (step 7) | `scout`, `reviewer` (read-only) |

The coder and the review talk through `findings.md` and `review.md` in the worktree root, as
described in `docs/workflow.md`. Both files are gitignored.

## Pull requests and CI

`.github/workflows/tests.yaml` runs `changes` and `docs` always. `lint` and the test jobs run
for code changes: `tests` (PHP 8.2 to 8.5 against Symfony 6.4 to 8.0; the PHP 8.2 / Symfony 6.4
leg enforces the coverage floor), `tests-root-permissions` (root-only `ConfigLoader` tests under
`sudo`; it fails if they skip) and an advisory `benchmark`. `ci-ok` aggregates them and is the
only required check. The `lint` job only runs `bin/lint.sh`. A weekly scheduled run executes
the full workflow and opens an issue when it fails.

The docs regex of `changes` is narrow on purpose: PHPUnit reads every tracked Markdown file
and `bin/lint.sh` checks `CHANGELOG.md`, so Markdown changes still run the tests.

## Release

See [docs/release-workflow.md](docs/release-workflow.md). Specifics of this repository:

- Default branch `master`, tags `vX.Y.Z`, milestones `vX.Y.Z`. No file carries the version
  (`composer.json` has no `version`).
- Breaking changes and new deprecations are written to `UPGRADE.md` in the same pull request
  as the code. Mark them **Breaking** in the CHANGELOG with a link to the `UPGRADE.md` section.
- `bin/check-changelog.php` (part of `bin/lint.sh`) validates the CHANGELOG structure: one
  `[Unreleased]` section first, `## [x.y.z] - YYYY-MM-DD` headings in descending order, each
  Keep a Changelog subheading once per version, and an issue reference on every entry.
- `release.yaml` creates the GitHub Release from the CHANGELOG section of the tag.

## Notes

- `var/` holds caches, coverage and the test daemon state; it is gitignored.
- `bin/` is linted by PHPStan, PHP-CS-Fixer and Rector as well.
- `.gitattributes` marks contributor-only files `export-ignore`: add new tooling there so it
  stays out of the Composer dist archive.
