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

`composer lint` includes the proof-of-work gate in `--advisory` mode: it
**reports but never fails**, because Composer aborts an array script on the
first non-zero command, so a gate that can fail inside `lint` would block every
push on every branch. The hook then runs the gate a second time — **only** on a
branch matching `^(fix|feat|process)/issue-<N>`, and there only evidence of
tampering (a `FAIL` finding) blocks; a cycle that is merely unfinished does not.
A hook that blocks every push is a hook people bypass with `--no-verify`, which
would make the whole gate fiction. The hard gate is CI (see `check-pow.php`).

The branch pattern is not written out in the installer: it is rendered from
`POWC_ISSUE_BRANCH_TYPES` in `pow-common.php`, so the hook, the gate and
`gh-branch` cannot drift apart.

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
(`[Bug]`→`fix`, `[Feat]`→`feat`, `[Tests]`→`test`, `[Process]`→`process`, …),
then from issue labels (`bug`/`security`→`fix`, `enhancement`→`feat`,
`documentation`→`docs`, `process`→`process`, …), and defaults to `fix`; an
explicit type argument always wins. The branch is created from the **fresh**
default remote branch (never from a stale local `master`).

Allowed types: `fix`, `feat`, `docs`, `perf`, `refactor`, `chore`, `test`,
`build`, `ci`, `process`. **`process` is not optional** for changes to the
workflow tooling itself — `bin/pow.php`, `bin/check-pow.php`,
`.github/workflows/*` and the `scripts` block of `composer.json` are protected
paths and `bin/check-pow.php` rejects a diff touching them from any other
branch prefix (see `check-pow.php` below).

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

### `check-pow.php`

Verifies the proof of work of the current pull request (see
`docs/workflow.md`, "Proof-of-work gate"). It is the enforcement half of
`pow.php`: nothing the orchestrator writes in prose is trusted, only
externally attested facts — a GitHub comment body and its server-assigned
timestamps, a harness artifact on disk, `git show` of an earlier commit, a
recomputed exit code.

**Usage:**
```bash
php bin/check-pow.php --advisory             # report only, always exit 0 (composer lint)
php bin/check-pow.php                        # fails on evidence of tampering (pre-push hook)
composer check-pow                           # the same, as a composer script
php bin/check-pow.php --strict               # "cannot determine" becomes a failure
php bin/check-pow.php --strict --verify-reality   # + recompute lint/test/coverage
php bin/check-pow.php --pr=700 --branch=fix/issue-686-x   # explicit target
```

**Skip or enforce.** The gate **enforces** when the branch matches
`^(fix|feat|process)/issue-<N>` or when the diff touches a protected path.
Everything else — a base branch (`master`/`main`, which is never gated against
itself), another branch, no pull request for the branch, `gh`
missing/unauthenticated/offline — is a **one-line skip with exit 0**.
`--strict` (used by CI) turns every "cannot determine" into a failure, because
in CI an unreadable fact is indistinguishable from a hidden one.

Findings carry a severity that decides who fails on them:

| Severity | Meaning | Fails |
| --- | --- | --- |
| `FAIL` | evidence of tampering | always, except under `--advisory` (printed as `WARN`) |
| `PENDING` | the cycle is not finished yet | `--strict` only |
| `UNKNOWN` | a fact could not be read | `--strict` only |
| `NOTE` | informational | never |

That split is what keeps the script usable mid-cycle: the proof of work is
legitimately incomplete until workflow step 11.5, but a tampered comment or a
leaked scratch buffer is never legitimate.

**What it enforces** (each check has a distinct, greppable id):

| Id | Check |
| --- | --- |
| `POW-01` | the PR has `closingIssuesReferences` — **no work without an issue** |
| `POW-02` | `docs/proof_of_work/<NNNN>-<slug>/` exists for that issue and holds a readable `manifest.json` whose `pow_version`, `issue` and `branch` match the pull request under test — renaming a recorded directory does **not** rebind it |
| `POW-03` | the manifest is complete for the profile it is **entitled** to (re-derived from the branch prefix and the issue labels, never read from the manifest): enough rounds, a readable ledger with no malformed row, no `open` entry, both machine exit codes, a verdict, and an `escalation.md` naming every open finding for `ACCEPT` |
| `POW-04` | nothing but `.gitkeep` from `docs/proof_of_work/current/` is in the diff |
| `POW-05` | the comment chain: every `comment_id` still exists **on this pull request** (`issue_url`), the re-fetched body hashes to `comment_sha256`, `updated_at == created_at` (an edit is detectable), the `prev` chain is intact (a deletion breaks it), and `created_at` increases strictly across rounds |
| `POW-06` | the ledger is append-only: for consecutive commits touching it, the older `findings.md` is a byte prefix of the newer one. **Inert in the documented flow**, which commits `findings.md` once at step 11.5 — it then says so as a `NOTE` rather than passing silently; the ledger's real anchor is `POW-05` |
| `POW-07` | no silent re-rolls: every `coder`/`review` artifact in `.pi-subagents/artifacts/` inside the branch time window appears in `rounds[]` or in `aborted[]` with a reason. **Local advisory only** — `.pi-subagents/` is gitignored, so the directory never exists on a runner and the check reports that it did not run. The window starts at the earliest **author** date on the branch (`%at`), which `git rebase` does not rewrite |
| `POW-08` | manifest vs reality: `--verify-reality` recomputes lint/test and compares `lint_exit`/`test_exit`, and compares `coverage` against `var/coverage.xml` (tolerance 0.05pp); a mismatch fails as `manifest falsified` |
| `POW-09` | the `no-pow` escape hatch, see below |
| `POW-10` | protected paths: a diff touching `bin/pow.php`, `bin/pow-common.php`, `bin/check-pow.php`, `.github/workflows/*` or the `scripts` block of `composer.json` requires a `process/` branch **and** a maintainer approval submitted **after** the newest protected-path commit (untracked files count — a new protected file is visible before it is committed) |
| `POW-11` | the environment kill switches are not honoured where they would matter: `CHECK_POW_SKIP` is ignored under `--strict`, `CHECK_POW_GH_FIXTURE` is ignored on a CI runner, and either being set there is itself a `FAIL` |

**The gate runs from `master`.** A pull request must not be able to weaken the
gate that judges it, so CI materialises the master copy first — **both files**,
since `check-pow.php` requires `pow-common.php` from its own directory:

```bash
git show origin/master:bin/check-pow.php  > "$gate/check-pow.php" \
  && git show origin/master:bin/pow-common.php > "$gate/pow-common.php" \
  && php "$gate/check-pow.php" --strict --verify-reality --pr=<n> --branch=<name>
```

When `origin/master:bin/check-pow.php` does not exist yet — the pull request
that introduces the gate — CI falls back to the in-tree copy with a loud
warning. That shell form is the **only** mechanism: there is deliberately no
`--self-check` flag, because locally `master` is whatever the developer's clone
happens to hold, so an in-script equivalent would advertise a property it
cannot deliver.

**Escape hatch.** The PR label `no-pow` (release PRs, reverts) skips checks
`POW-01`–`POW-08`, but only when the PR also carries a maintainer approval on
record *and* the bypass is named in `docs/process-changelog.md`. The bypass is
always printed loudly — a bypass is a documented exception, never a silent one.

Requires the `gh` CLI (authenticated) for everything that reads GitHub; the
local checks (`POW-04`, `POW-06`, `POW-07`, the branch half of `POW-10`) work
offline. Exit codes: 0 = pass or skip, 1 = gate violation, 2 = usage error.

Environment variables, none needed in normal use: `CHECK_POW_ROOT` points the
script at another repository root, `CHECK_POW_SKIP=1` makes it exit 0
immediately (it is set automatically for the subprocesses `--verify-reality`
spawns, so the `composer lint` it runs does not recurse) but is **ignored under
`--strict`**, `CHECK_POW_GH_FIXTURE` replaces every `gh` call with a JSON file
(used by the test suite) but is **ignored when `CI`/`GITHUB_ACTIONS` is
truthy**, and `CHECK_POW_LINT_CMD` / `CHECK_POW_TEST_CMD` override the commands
`--verify-reality` recomputes with. Setting either of the first two where it is
ignored is reported as a `POW-11` violation: a kill switch that outranks the
gate is not a gate.

### `pow-common.php`

Not a command — the single source for every rule `pow.php` (the recorder) and
`check-pow.php` (the gate) have to agree on. Both `require_once` it; `bin/` is
outside the PSR-4 autoloader by design, so a plain `require` is the mechanism.

It holds the ledger row parser and its effective-status rule, the completeness
and escalation rules `--finish` and `POW-03` share, the round caps and minimum
round counts per profile, the branch-prefix profile rule, the base-ref list, the
subprocess runner with its concurrent pipe drain, and the issue-branch pattern
in its PCRE and POSIX-ERE renderings (`install-git-hook.php` renders the hook's
`grep -E` from it).

Every one of those used to exist twice, and every copy had drifted: the gate
accepted a three-byte `escalation.md` the recorder refused, and it deadlocked on
the first 64 KB of a child's stderr where the recorder did not. Add a shared
rule here, not in one of the two scripts.

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
