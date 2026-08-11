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
`docs/workflow.md`, step 2). The type is inferred from a `[Type]` title prefix
(`[Bug]`→`fix`, `[Feat]`→`feat`, `[Tests]`→`test`, …), then from issue labels
(`bug`/`security`→`fix`, `enhancement`→`feat`, `documentation`→`docs`, …),
and defaults to `fix`; an explicit type argument always wins. The branch is
created from the **fresh** default remote branch (never from a stale local
`master`).

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

### `pow.php`

Records the **proof of work** of one issue cycle (see `docs/workflow.md`,
steps 2.5–6, and [../docs/proof_of_work/README.md](../docs/proof_of_work/README.md)).
The cycle narrative lives in PR comments; only `manifest.json`, `findings.md`
and — when it exists — `escalation.md` are committed, under
`docs/proof_of_work/<NNNN>-<slug>/`.

Round comments are never authored by the orchestrator: `--round` reads the
harness artifact `.pi-subagents/artifacts/<runId>_<agent>_0_output.md`,
injects front matter derived from the neighbouring `_meta.json`, publishes it
verbatim with `gh pr comment`, and records the comment id, the server-assigned
`created_at` and the sha256 of the exact published body. An unknown `run_id`
is refused.

**Usage:**
```bash
php bin/pow.php --start --issue=686 [--slug=<kebab>] [--branch=<name>] [--profile=full|light]
php bin/pow.php --round=2 --role=review --run=<runId> [--dry-run]
php bin/pow.php --finding --id=F-01 --round=1 --loc=src/Foo.php:12 \
                --desc="…" --severity=high [--status=open]
php bin/pow.php --resolve --id=F-01 --round=2 --status=fixed --resolution="…"
php bin/pow.php --verdict=CLEAN            # or NARROW | REDO | ACCEPT | HUMAN
php bin/pow.php --set lint_exit=0 --set test_exit=0 --set coverage=81.4
php bin/pow.php --gate="regression test for X" --abort=<runId>:<reason>
php bin/pow.php --status                   # summary of the cycle in progress
php bin/pow.php --finish                   # validate + move into <NNNN>-<slug>/
php bin/pow.php --abort --reason="…"       # archive current/ to .abandoned/<ts>/
```

`--status` and `--abort` are commands when written bare and options when
written with a value (`--status=fixed`, `--abort=<runId>:<reason>`), so their
value form always requires the `=` sign.

Rules the script enforces:

- **round cap** — `full` profile 4 rounds, `light` 2. Beyond the cap the
  script refuses and points at the `oracle` verdict plus `escalation.md`;
  there is no round 5.
- **append-only ledger** — `--resolve` appends a NEW row for the same ID, it
  never edits one. A `wontfix` must cite `decisions.md#<anchor>` or
  `escalation.md`.
- **verdicts** — anything but `CLEAN` requires a non-empty `escalation.md`;
  `ACCEPT` additionally requires every still-open finding to be named there.
- **`--start` never deletes** — a non-empty `current/` is archived to
  `.abandoned/<ts>/`.
- **`--finish` recomputes** `commits[]`, `files_changed[]` and
  `findings{total,round1,escaped,open}` from git and the ledger rather than
  trusting declared values.

Requires the `gh` CLI (authenticated) for `--round` and for the issue
title/labels lookup in `--start`; everything else works offline. Exit codes:
0 = ok, 1 = runtime/validation error, 2 = usage error.

Two environment variables exist for the test suite and are not needed in
normal use: `POW_ROOT` points the script at another repository root (default:
the parent of `bin/`), and `POW_NO_GH=1` disables every `gh` call, which makes
`--round` require `--dry-run`.
