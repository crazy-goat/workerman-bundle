# Bin Directory

This directory contains development and contribution scripts for this bundle.
It is **not** the Symfony console you use to run the Workerman server.

For the Workerman server commands, use your **application's** `bin/console`
(e.g., `bin/console workerman:server start`).

## Scripts

### `install-git-hook.php`

Installs a pre-push git hook that runs `composer lint` before each push.
The hook is automatically installed by Composer via the `post-install-cmd`
and `post-update-cmd` scripts.

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

### `gh-branch`

Creates or switches to the `<type>/issue-<N>-<slug>` branch for a GitHub issue,
so the branch name never needs to be invented by hand or by an LLM (see
`docs/workflow.md`, step 2). The type is inferred from issue labels
(`bug`/`security`→`fix`, `enhancement`→`feat`, `documentation`→`docs`, …),
then from a `[Type]` title prefix, and defaults to `fix`; an explicit type
argument always wins. The branch is created from the **fresh** default remote
branch (never from a stale local `master`).

**Usage:**
```bash
bin/gh-branch 491                 # create/switch to the issue branch
bin/gh-branch 491 feat            # force type override
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
