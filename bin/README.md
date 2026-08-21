# Bin Directory

This directory contains development and contribution scripts for this bundle.
It is **not** the Symfony console you use to run the Workerman server.

For the Workerman server commands, use your **application's** `bin/console`
(e.g., `bin/console workerman:server start`).

## Scripts

### `install-git-hook.php`

Installs a pre-push git hook that runs `composer lint` before each push. The
hook is automatically installed by Composer via the `post-install-cmd` and
`post-update-cmd` scripts.

A lint failure blocks the push. That is the whole hook — there is nothing else
in it, and the hard gate is still CI.

**Manual reinstall:**
```bash
php bin/install-git-hook.php
```

**Remove:**
```bash
rm .git/hooks/pre-push
```

**Skip on push:**
```bash
git push --no-verify
```

### `check-coverage.php`

Parses a PHPUnit Clover XML file and exits non-zero when total line coverage
is below a threshold. Used by `composer coverage:check`.

### `check-changelog.php`

Structurally validates `CHANGELOG.md`: exactly one `[Unreleased]` heading and
it comes first, released headings match `## [x.y.z] - YYYY-MM-DD` with a real
calendar date, in strictly descending order, Keep a Changelog subheadings
(`Added`, `Changed`, `Fixed`, `Removed`, `Deprecated`, `Security`) appear at
most once per version block, and every top-level entry carries an issue
reference — except the entries frozen in the script's
`LEGACY_ENTRIES_WITHOUT_A_REFERENCE` list. Lines inside fenced code blocks
(``` or `~~~`) are ignored — a fenced example heading is documentation, not
structure — and an unterminated fence is reported at its opening line instead
of producing misleading downstream messages. References are matched against
prose only: inline-code spans are stripped and
an anchor link (`[x](#123)`) does not count. Wired into `composer lint`, so
the pre-push hook and the CI Lint job run it too;
`tests/ChangelogStructureTest.php` drives the same script as a subprocess
against synthetic fixtures.

**Usage:**
```bash
php bin/check-changelog.php                # what composer lint runs
composer changelog:check                   # the same, as a composer script
php bin/check-changelog.php --root=/path/to/checkout
```

Exit codes: 0 = valid, 1 = violations found, 2 = usage error. `--root=` (or
the `CHANGELOG_CHECK_ROOT` environment variable, itself reported as a
warning) points the check at another checkout; the resolved root is always
printed so it is never ambiguous which tree was checked.

### `gh-branch`

Creates or switches to the `<type>/issue-<N>-<slug>` branch for a GitHub issue,
so the branch name never needs to be invented by hand or by an LLM (see
`docs/workflow.md`, step 2). The type is inferred from a `[Type]` title prefix
(`[Bug]`→`fix`, `[Feat]`→`feat`, `[Tests]`→`test`, `[Process]`→`process`, …),
then from issue labels (`bug`/`security`→`fix`, `enhancement`→`feat`,
`documentation`→`docs`, `process`→`process`, …), and defaults to `fix`; an
explicit type argument always wins. The branch is created from the **fresh**
default remote branch (never from a stale local `master`).

Allowed types: `fix`, `feat`, `docs`, `perf`, `refactor`, `chore`, `test`,
`build`, `ci`, `process`. Use `process` for changes to the workflow itself —
`docs/workflow.md`, `.github/workflows/*` or the `scripts` block of
`composer.json` — so that "we changed the rules" is visible in the branch name
rather than buried in a diff.

**Usage:**
```bash
bin/gh-branch 491                 # create/switch to the issue branch
bin/gh-branch 491 feat            # force type override
bin/gh-branch 686 process         # workflow/tooling change (protected paths)
bin/gh-branch 491 --push          # create + push with upstream
bin/gh-branch 491 --dry-run       # print branch name only (no git mutation)
bin/gh-branch 491 --force         # create despite dirty tree / non-default branch
```

Creation is refused on a dirty working tree or when not on the default
branch — `--force` overrides (uncommitted changes are carried to the new
branch, exactly as with `git switch -c`).

Prints the branch name to stdout, so it can be captured
(`branch=$(bin/gh-branch 491)`). All messages go to stderr.

Requires the `gh` CLI (authenticated); GitHub-issue repos only — nothing
Jira/decodo related. Exit codes: 0 = ok, 1 = environment/issue/dirty-tree
error, 2 = usage error.

### `pick-issue.php`

Ranks the open issues of the lowest open milestone and prints the top
candidates with an explainable score, so the next issue can be picked
cheaply by a human or an LLM (see `docs/workflow.md`, step 1). Exits with
code 3 — "RELEASE NEEDED" — when the target milestone has no open issues
left: stop the workflow and cut a release.

**Usage:**
```bash
php bin/pick-issue.php                             # top 5 of the lowest milestone
php bin/pick-issue.php --milestone=0.7.0 --top=5   # explicit milestone
php bin/pick-issue.php --json                      # machine-readable output
```

Requires the `gh` CLI (authenticated). Exit codes: 0 = candidates,
1 = gh/API error, 2 = usage error, 3 = release needed.

### `kb-lint.php`

Lints the subagent knowledge base in `docs/helpers/` (`faq.md`, `decisions.md`)
and regenerates its tag index. Wired into `composer lint`; `composer lint-fix`
runs it with `--fix`. See
[docs/helpers/README.md](../docs/helpers/README.md) for the entry format and the
decay rules it enforces.

**Usage:**
```bash
php bin/kb-lint.php            # what composer lint runs
composer kb-lint               # the same, as a composer script
php bin/kb-lint.php --fix      # regenerate the tag index of every KB file
php bin/kb-lint.php --json     # machine-readable output
php bin/kb-lint.php --root=/path/to/checkout
```

**What it checks:**

| Check | Severity |
| --- | --- |
| every `###` entry is followed by a single-line `<!-- kb: … -->` front-matter comment | error |
| required keys present (`id`, `date`, `tags`, `trigger`, `hits`, `status`), no unknown keys | error |
| `id` matches `FAQ-NNN` / `DEC-NNN` for its file and is unique **across both files** | error |
| `date` is an ISO-8601 calendar date, `hits` a non-negative integer, `tags` lowercase `[a-z0-9-]` | error |
| `status` is one of `active` / `promoted` / `stale` | error |
| a `promoted` entry names its `gate=` and collapses to at most two body lines | error |
| a front-matter value contains `-->` (it would terminate the comment early and leak the tail into the rendered page) | error |
| the tag index between `<!-- kb-index:start -->` / `<!-- kb-index:end -->` matches the entries | error (fixed by `--fix`) |
| more than one tag index block in a file | error |
| a file is over the 300-line budget — the generated index, its `## Tag index` heading and the blank lines around it do not count | warning |
| near-duplicate entries | warning |
| `stale` entries (0 hits in 20 cycles) | listed |

Near-duplicate detection is a cheap heuristic, not a proof: entry titles and
bodies are lowercased, split on non-alphanumerics, stripped of stop words and
of tokens shorter than three characters, and two entries are reported when
their **overlap coefficient** — `|A ∩ B| / min(|A|, |B|)`, so a short entry
fully contained in a long one still scores high — reaches **0.75**. The
15-distinct-token minimum applies to the **larger** entry of the pair only:
applying it to both would skip exactly the short-inside-long case the overlap
coefficient was chosen to catch. `promoted` entries are excluded — they are
one-line pointers by design.

Works entirely offline. Exit codes: 0 = clean (warnings may still be printed),
1 = lint failure, 2 = usage error.

The resolved root is always printed (`kb-lint: root <absolute path>`, and
`root` / `root_from_env` in `--json`) so it is never ambiguous which tree was
linted. `KB_LINT_ROOT` points the script at another repository root and is
itself reported as a warning; the `--root=` option wins over it and is not
warned about. Neither is needed in normal use.
