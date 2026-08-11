# Workflow: Issue → Feature Branch → Draft PR → Implementation → Review Rounds → CI → Merge

This document describes the complete workflow for handling issues in the
[crazy-goat/workerman-bundle](https://github.com/crazy-goat/workerman-bundle)
repository using `gh` and `git`.

Every cycle leaves a **proof of work**: the round narrative in PR comments,
generated from harness artifacts, plus a committed manifest and an
append-only findings ledger under `docs/proof_of_work/`. See
[Proof of Work](#proof-of-work-docsproof_of_work) below and
[proof_of_work/README.md](proof_of_work/README.md).

That proof of work is **enforced, not requested**: `bin/check-pow.php` runs in
`composer lint`, in the pre-push hook and — as the hard gate — in CI. See
[Proof-of-work gate](#proof-of-work-gate-bincheck-powphp).

---

## 1. Browse Open Issues via Subagent

Browsing and triaging open issues is token-heavy (titles, bodies, labels,
comments, related code). Delegate it to a subagent with its own context.

```bash
# The subagent receives a task like:
# "List the top 5 most impactful open issues in crazy-goat/workerman-bundle.
#  For each, return: number, title, labels, one-paragraph rationale.
#  Do NOT propose branch names — bin/gh-branch derives them in step 2.
#  Prioritize: enhancement, code-quality, good-first-issue,
#  stability/data-correctness/performance, blockers, user-facing (README/API docs)."
```

The subagent uses `gh issue list --state open --limit 100` and
`gh issue view <NUMBER> --json title,body,labels,state` to gather data, then
returns a ranked shortlist. The main session picks one issue from the
shortlist and proceeds to step 2.

> **Note:** `gh issue list` returns **at most 30 issues by default** — the
> triage task must explicitly raise `--limit` (e.g. `--limit 100`, max 1000)
> so issues beyond the first page are not missed.

> **Why a subagent:** issue bodies, comments, and related code can easily
> exceed thousands of tokens. Keeping this in a separate context protects the
> main session's budget for implementation and review.

### Fast path: ranked candidates via `bin/pick-issue.php`

The workflow is milestone-driven, so before delegating triage to a subagent,
run the ranking script — it costs a few tokens instead of thousands:

```bash
php bin/pick-issue.php                            # top 5 of the lowest open milestone
php bin/pick-issue.php --milestone=0.7.0 --top=5  # explicit milestone
php bin/pick-issue.php --json                     # machine-readable, for scripting
```

The script lists open milestones, picks the **lowest** one (semver), scores
its open issues — type labels (`bug`/`security`/`enhancement`/…), priority
labels (`critical`/`high`/`medium`/`minor`), title signals (leak/crash/
security/performance), age and comment count — and prints the top N with an
explicit per-issue score breakdown. It never reads issue bodies or comment
text: the API payload is projected down to titles, labels, dates and comment
counts at parse time, and it always paginates (`gh` caps lists at 30 by
default). The LLM/user still makes the final pick from the ranked candidates;
the script only narrows the pool.

> **Release gate:** when the target milestone has 0 open issues left, the
> script exits with code **3** and prints the release steps instead of
> candidates — the workflow STOPS, a release must be cut and the milestone
> closed before picking again (see "Release Gate" below).

**Selection criteria (applied by the subagent):**
- Issues labeled `enhancement`, `code-quality`, `good-first-issue`
- Issues about stability, data correctness, performance
- Issues blocking other tasks
- Issues most relevant to users (README, API documentation)

---

## Release Gate

Work is driven by the **lowest open milestone**: a milestone is a release
candidate, not a bottomless backlog. When the current milestone has no open
issues left, the workflow must **stop** — do not silently pick an issue from
a higher milestone.

`php bin/pick-issue.php` enforces this:

- exit code `0` → candidates printed, proceed to step 2
- exit code `3` (`RELEASE NEEDED`) → the target milestone is empty; cut
  the release and close the milestone, then re-run the script so the next
  milestone becomes the target — see
  [release-workflow.md](release-workflow.md) for the full release steps

---

## 2. Create a Fresh Feature Branch

Run the helper — the branch name is derived from the issue, nobody (human or
LLM) has to invent it:

```bash
bin/gh-branch <NUMBER>             # creates/switches to <type>/issue-<N>-<slug>
bin/gh-branch <NUMBER> feat        # force type (fix|feat|docs|perf|refactor|chore|test|build|ci|process)
bin/gh-branch <NUMBER> process     # workflow/tooling change — required for protected paths
bin/gh-branch <NUMBER> --push      # also push with upstream
branch=$(bin/gh-branch <NUMBER>)   # capture the name (printed to stdout) for later steps
```

The `fix`/`feat`/… type is inferred from a `[Type]` title prefix
(`[Bug]`→`fix`, `[Feat]`→`feat`, `[Tests]`→`test`, `[Process]`→`process`, …),
falling back to issue labels (`bug`/`security`→`fix`, `enhancement`→`feat`,
`documentation`→`docs`, `process`→`process`, …), and finally to `fix`. An issue
labelled `process` therefore lands on a `process/` branch — which is the only
prefix allowed to touch the workflow tooling, see
[the protected-path rule](#protected-paths). The branch is created from the
fresh remote default branch, so no manual fetch/pull is needed. If the branch
already exists (locally or on origin) it switches to it instead; a dirty
working tree or being on a non-default branch aborts **creation** — use
`--force` to proceed anyway (uncommitted changes are then carried to the new
branch, exactly as with `git switch -c`). Use `--dry-run` to see the name
without touching git.

**Branch naming convention:** `fix/issue-<NUMBER>-<kebab-case>`
or `feat/issue-<NUMBER>-<kebab-case>` (e.g. `feat/issue-491-update-readme`) —
the script produces exactly this shape.

Existing examples in this repository:
- `feature/295-servermanager-magic-timeout-constants`
- `fix/270-runtime-directory-restrictive-mode`
- `docs/282-bin-directory-unexplained`

---

## 2.5 Open the Draft PR and Start the Proof of Work

The draft PR is created **right after the branch**, before a single line is
written. It is not paperwork: it is where the round comments live, it starts
CI earlier, and it makes `closingIssuesReferences` exist from the first push
instead of from step 9.

```bash
git push -u origin "$branch"          # an empty branch is enough
gh pr create --draft \
  --title "feat: <short description> (closes #<NUMBER>)" \
  --body "Closes #<NUMBER>

Work in progress — see the round comments below." \
  --base master --assignee @me
```

Then open the proof-of-work cycle:

```bash
php bin/pow.php --start --issue=<NUMBER>
php bin/pow.php --status               # sanity check: issue, branch, profile, cap
```

`--start` writes `docs/proof_of_work/current/manifest.json` and the
`findings.md` ledger header. `current/` is a **gitignored scratch buffer**, so
nothing half-written can leak into the PR; if a previous cycle was left behind
there, it is archived to `.abandoned/<ts>/` rather than deleted.

### Profiles

The profile decides how many review rounds the cycle may take and whether the
gate step is mandatory. It is selected from the branch prefix:

| Profile | Branch prefixes | Round cap | Gate step |
| --- | --- | --- | --- |
| `full` | `fix`, `feat`, `refactor`, `perf`, `process` | **4** | mandatory |
| `light` | `docs`, `chore`, `ci`, `test`, `build` | **2** | not required |

An issue labelled `process` is **always** `full`, whatever the branch says —
changes to the workflow itself do not get the short loop. `--profile=` can
override the branch-derived value, but not the `process` label.

See [proof_of_work/README.md](proof_of_work/README.md) for what is stored
where and why, and `bin/README.md` for the full `bin/pow.php` reference.

---

## 3. Implement the Change (via Worker/Coder Subagent)

Implementation is delegated to a subagent (`worker` or `coder`) so the main
session stays free to orchestrate, review findings, and handle the next steps.

```bash
# The subagent receives a task like:
# "Implement issue #<NUMBER> on branch feat/issue-<NUMBER>-<description>.
#  Read docs/helpers/ (faq.md, decisions.md) first — it documents
#  recurring pitfalls and project decisions that apply to this task.
#  Read the issue body first, then make the smallest correct change.
#  Run the relevant tests for the changed behavior.
#  Commit and push when done.
#
#  Your report must ALWAYS contain:
#  1. Files changed and why
#  2. What was the BIGGEST problem or obstacle during implementation
#     (with details: where, why it was hard, how you solved it)
#  3. Any bugs or places to improve you discovered along the way
#     (also outside the scope of this issue) - each with file/line,
#     short description, and suggested fix"
```

After the subagent reports, commit and push if it did not do so already:

```bash
# Ensure everything is committed and pushed
git add -A
git commit -m "feat(core): implement <short description> (closes #<NUMBER>)"
git push origin feat/issue-<NUMBER>-<description>
```

Then publish the coder's report as the round-N coder comment. The report is
**not** retyped by the main session — `bin/pow.php` takes the harness artifact
of that run and posts it verbatim:

```bash
php bin/pow.php --round=1 --role=coder --run=<runId>
```

`<runId>` is the id of the subagent run, i.e. the prefix of
`.pi-subagents/artifacts/<runId>_<agent>_0_output.md`. The script derives the
agent and the model from the artifact itself, injects front matter, publishes
the comment, and records `comment_id`, the server-assigned `created_at` and
the sha256 of the exact published body in the manifest. An unknown `run_id` is
refused — a round with no artifact on disk is not a round.

If a run was thrown away (crashed, wrong scope, re-rolled), record it instead
of pretending it never happened:

```bash
php bin/pow.php --abort=<runId>:"wrong scope, re-ran with a narrower task"
```

**Commit message convention:**
- Type: `feat`, `fix`, `docs`, `refactor`, `ci`, `test`, `chore`
- Scope: `(core)`, `(runtime)`, `(command)`, `(config)`, `(ci)`, `(dto)` etc.
- Reference to issue: `(closes #<NUMBER>)`

> **Coder output contract (non-negotiable):** the subagent must always return
> (1) changed files, (2) the biggest problem it faced with details, and
> (3) any discovered bugs / places to improve - even ones outside the current
> issue's scope. The main session stores these findings for the final report
> (step 14).

---

## 4. Code Review via Subagent (ledger-driven)

After implementation, run a code review using a subagent (separate agent with
its own context). The subagent checks:

- Alignment with project structure (PSR-4, Symfony Bundle conventions)
- Type correctness and signatures (PHPStan level 8)
- Error handling and edge cases
- Coding style (PSR-12, php-cs-fixer)
- Test coverage
- Security (Workerman child processes, HTTP input, process supervision)

**Review round N walks the ledger first.** Before looking for anything new it
reads `docs/proof_of_work/current/findings.md` and confirms or rejects every
entry that is still `open`. No finding may silently disappear between rounds —
that is the whole point of the ledger being append-only.

```bash
# The subagent receives a task like:
# "Code review the changes in files: <list of files>.
#  Read docs/helpers/ (faq.md, decisions.md) first and flag any
#  violations of documented decisions.
#  Then read docs/proof_of_work/current/findings.md and, for every entry
#  whose effective status is 'open', state explicitly: still present /
#  fixed / not a real finding (with evidence).
#  Only then look for NEW issues.
#  Check: type correctness, error handling, PSR-12 compliance,
#  missing tests, outdated documentation.
#  Return findings as: ID | file:line | description | severity."
```

Publish the review report and append its new findings to the ledger:

```bash
php bin/pow.php --round=1 --role=review --run=<runId>

php bin/pow.php --finding --id=F-01 --round=1 \
  --loc=src/Http/ResponseConverter.php:88 \
  --desc="HEAD response keeps a stale Content-Length" --severity=high
```

Severities are `high`, `medium`, `low`, `nit`. IDs are `F-01`, `F-02`, … and
are **never reused**: an ID belongs to one finding for the whole cycle.

After the review, the subagent should append any non-obvious findings to
`docs/helpers/` (see "Knowledge Base (docs/helpers/)" below) — typically as
part of the fix commits that follow.

> **Why a subagent:** code review reads the full diff plus surrounding code,
> runs static analysis, and produces a structured findings list. Delegating
> keeps the main session focused on fixes and the next workflow step.

---

## 5. Fix Findings and Close Their Ledger Rows

```bash
# For each finding:
# 1. Apply the fix
# 2. Commit with a descriptive message
git add -A
git commit -m "fix: <description of fix>"
git push origin feat/issue-<NUMBER>-<description>

# 3. Append a resolution row — the old row is never edited
php bin/pow.php --resolve --id=F-01 --round=2 --status=fixed \
  --resolution="stale header stripped in ResponseConverter (abc1234)"
```

Statuses a resolution may carry:

| Status | Meaning |
| --- | --- |
| `fixed` | the code changed and the finding is gone |
| `gated` | an automated check (test / PHPStan rule / lint rule) now catches this class of defect |
| `wontfix` | deliberately not fixed — **must** cite `decisions.md#<anchor>` or `escalation.md`, the script rejects an uncited `wontfix` |

**All findings must be resolved — even the `nit`s.** "Resolved" means the
ledger has a closing row, not that everybody stopped talking about it.

> **Escape rate:** a finding first seen in round 2 or later is an *escaped*
> defect — round 1 should have caught it and did not. `--finish` counts them
> into `findings.escaped`. A high escape rate is a missing check, not an
> unlucky reviewer, so prefer `gated` over `fixed` whenever an automated rule
> could have found it.

---

## 6. Repeat the Review — Hard Cap of 4 Rounds

After fixing, invoke the review subagent again and record the new round:

```bash
php bin/pow.php --round=2 --role=review --run=<runId>
```

Repeat steps 5 → 6 until the review reports no open findings.

> **Acceptance criteria:** the review confirms every ledger entry is resolved
> and reports no new ones. Record it and move on:
>
> ```bash
> php bin/pow.php --verdict=CLEAN
> ```

### The cap

`full` cycles get **4** rounds, `light` cycles get **2**. `bin/pow.php`
refuses a round beyond the cap: **there is no round 5.** A loop that has not
converged in four rounds is not going to converge in five — it needs a
decision, not another iteration.

At the cap, run the `oracle` subagent in a fresh context. It reads the issue,
the diff and the ledger, and picks **exactly one** binding verdict:

| Verdict | Meaning | What happens next |
| --- | --- | --- |
| `NARROW` | the issue is too big for one cycle | split it: keep the converged part, file the rest as new issues, and finish this cycle on the reduced scope |
| `REDO` | the approach is wrong, not the details | abandon the branch (`bin/pow.php --abort --reason=…`), re-plan, start a fresh cycle |
| `ACCEPT` | the remaining findings are genuinely acceptable | **every** still-open finding must be justified individually in `escalation.md` |
| `HUMAN` | the decision is not the agent's to make (BC surface, security policy, release process) | stop and ask the user |

The oracle writes `docs/proof_of_work/current/escalation.md` — the reasoning,
the verdict, and one paragraph per still-open finding. Then:

```bash
php bin/pow.php --verdict=ACCEPT    # or NARROW | REDO | HUMAN
```

Any verdict other than `CLEAN` requires a non-empty `escalation.md`, and
`ACCEPT` additionally requires every open finding ID to be named there —
otherwise the script exits with
`ACCEPT with unjustified findings: F-03, F-07`. An `ACCEPT` that waves at
"some minor remaining issues" is rejected by construction.

> **Never lower a gate to reach a verdict.** Dropping the coverage floor or
> disabling a linter rule to make a round look clean is forbidden outright
> (see `docs/helpers/decisions.md`); it is a `HUMAN` verdict at best.

---

## 7. Run Linters and Tests Locally

Before opening a PR, verify that all linters and tests pass on your machine:

```bash
# Run all linters (php-cs-fixer dry-run, phpstan, rector dry-run) and the
# advisory proof-of-work gate (php bin/check-pow.php)
composer lint

# Auto-fix fixable issues (php-cs-fixer, rector)
composer lint-fix

# Run tests (boots a real Workerman daemon on ports 8888 and 9999)
composer test

# (Optional) Verify the coverage gate locally — requires PCOV or Xdebug:
composer test:coverage
composer coverage:check
```

> **Note:** CI enforces a line-coverage floor of **80%**, defined once in
> `composer.json` (`coverage:check`) and checked on the PHP 8.2 / Symfony 6.4
> matrix leg. If your PR adds meaningful logic, verify the gate locally so CI
> doesn't tell you first.

> **Note:** `composer test` boots a real Workerman daemon binding ports 8888
> and 9999 for E2E tests. If you see "Address already in use" errors, ensure
> those ports are free. To stop the server manually if tests were interrupted:
> `php tests/App/index.php stop`

After `composer lint-fix`, commit any fixes:

```bash
git add -A
git commit -m "style: auto-fix lint issues"
```

Record the machine facts — the exit codes are the evidence, not the claim
that "everything passed":

```bash
php bin/pow.php --set lint_exit=0 --set test_exit=0
php bin/pow.php --set coverage=81.4        # when the coverage run was done
```

**Only mark the PR ready for review when all lints and tests pass locally.**

---

## 8. Update CHANGELOG.md

```bash
# Edit CHANGELOG.md:
# - Add entry under [Unreleased] section
# - Follow Keep a Changelog format (https://keepachangelog.com/en/1.1.0/)
# - Use appropriate section: Added, Changed, Fixed, Removed, Deprecated
# - Include issue number, e.g. (#491)
```

---

## 9. Mark the Pull Request Ready for Review

The PR already exists — it was opened as a draft in step 2.5. Fill in the
body and take it out of draft:

```bash
gh pr edit --title "feat: <short description> (closes #<NUMBER>)" \
  --body "## Description

Closes #<NUMBER>

## Changes

- <list of changes>

## Changelog

<!-- Describe the changelog entry for this PR -->

## Proof of Work

- Rounds: see the \`bin/pow.php\` comments on this PR
- Ledger: \`docs/proof_of_work/<NNNN>-<slug>/findings.md\`
- Verdict: <CLEAN | NARROW | REDO | ACCEPT | HUMAN>

## Code Review

- [ ] Passed subagent code review
- [ ] All ledger findings resolved"

gh pr ready
```

> **Note:** If you don't use `gh`, create the PR manually via GitHub UI.
> Branch protection requires **at least 1 approving review** before merge.

---

## 10. Wait for CI

```bash
# Check PR status
gh pr view --json statusCheckRollup

# Wait for all checks to finish
gh pr checks --watch
```

CI workflow (`.github/workflows/tests.yaml`) runs:
1. **lint** – composer validate, composer audit, php-cs-fixer, phpstan, rector,
   plus the advisory proof-of-work gate
2. **tests matrix** (PHP 8.2–8.5 × Symfony 6.4–8.0) – unit + E2E tests
3. **pow** – the `origin/master` copy of `bin/check-pow.php` with
   `--strict --verify-reality` (skipped while the PR is a draft), see
   [Proof-of-work gate](#proof-of-work-gate-bincheck-powphp)
4. **ci** – aggregator checking that lint, tests and pow passed (a skipped
   `pow` job is not a failure)

---

## 11. Handle CI Failures

If CI fails:

```bash
# 1. See which checks failed
gh pr checks

# 2. View logs
gh run view --log --job <job-name>

# 3. Fix the issues locally
# 4. Run code review via subagent again (repeat steps 4-6)
# 5. Commit the fixes
git add -A
git commit -m "fix: <description of CI fix>"
git push origin feat/issue-<NUMBER>-<description>

# 6. Wait for CI to re-run
gh pr checks --watch
```

> **Note:** The pre-push hook runs `composer lint` and then
> `php bin/check-pow.php` before every push. The proof-of-work gate warns
> always but blocks only on `^(fix|feat|process)/issue-<N>`.
> To skip the hook in emergencies: `git push --no-verify` — CI will still say
> no, that is the point.

**Repeat until all CI checks pass.**

> **A CI failure is an escaped defect.** Record it in the ledger like any
> other finding before fixing it — round 1 should have caught it and did not,
> which is exactly what `findings.escaped` measures:
>
> ```bash
> php bin/pow.php --finding --id=F-04 --round=<current round> \
>   --loc=.github/workflows/tests.yaml:1 \
>   --desc="PHP 8.5 leg fails on …" --severity=high
> php bin/pow.php --set lint_exit=0 --set test_exit=0   # after the fix
> ```

---

## 11.5 Close the Proof of Work

Run this once CI on the PR is green: the proof-of-work commit is the last
commit of the cycle, so the recorded `lint_exit`/`test_exit` and the recomputed
`commits[]`/`files_changed[]` describe the state that is actually merged.

```bash
php bin/pow.php --finish
git add docs/proof_of_work
git commit -m "docs(pow): proof of work for #<NUMBER>"
git push origin feat/issue-<NUMBER>-<description>
```

`--finish` recomputes `commits[]` and `files_changed[]` from git and
`findings{total,round1,escaped,open}` from the ledger — declared values are
never trusted — validates the manifest against its profile, then **moves**
`manifest.json`, `findings.md` and `escalation.md` into
`docs/proof_of_work/<NNNN>-<slug>/` and resets `current/` to just `.gitkeep`.

It refuses to finish an incomplete cycle: too few rounds for the profile, a
missing verdict, unset `lint_exit`/`test_exit`, open findings under a `CLEAN`
verdict, or open findings with no `escalation.md` justifying them.

If the cycle is being abandoned rather than finished, archive it instead —
nothing is deleted:

```bash
php bin/pow.php --abort --reason="superseded by the NARROW split of #<NUMBER>"
```

---

## 12. Merge PR and Close Issue

```bash
# Merge PR (squash merge recommended for clean history)
gh pr merge --squash --delete-branch

# Close the issue (automatic if commit contains "closes #<NUMBER>")
# Alternatively:
gh issue close <NUMBER>
```

> **Note:** If branch protection requires a review, `gh pr merge` may be
> blocked. In that case, use the GitHub UI to squash-merge after obtaining
> approval.

---

## 13. Switch Back to master

```bash
git checkout master
git pull origin master
```

---

## 14. Report Implementation Problems and Offer a GitHub Issue

At the end of the workflow, present the findings collected from the
implementation subagent(s) and decide with the user whether they deserve a
dedicated GitHub issue.

**Display to the user:**

1. **Biggest problem(s) faced during implementation** - as reported by the
   worker/coder subagent in step 3.
2. **Discovered bugs / places to improve** - each with file/line, short
   description, and suggested fix (including findings outside the scope of the
   issue just closed).

**Verify each candidate finding with a review subagent (read-only) before
offering or creating an issue.** For every candidate finding the subagent
must confirm:

1. **The finding is real** - read the cited file/line(s) on the current
   branch and confirm the behavior actually occurs and is reachable; check
   whether it is by-design and already documented (those are skipped, not
   filed).
2. **No similar issue exists on GitHub** - search open *and* closed issues.
   `gh issue list` returns at most 30 issues by default, so always pass an
   explicit limit:

   ```bash
   gh issue list --state open --limit 150 --json number,title,labels,body
   gh issue list --state closed --limit 150 --json number,title,labels
   gh search issues --repo <owner>/<repo> --state open --limit 50 "<keyword>"
   ```

   Same or overlapping scope counts as tracked; known related issues (e.g.
   referenced from CHANGELOG entries) must be checked explicitly.
3. **A recommendation per finding**: (a) create a new issue - with proposed
   title and labels per the project's conventions (`bug` / `enhancement` /
   `code-quality` / `minor` / …), (b) skip - already tracked (cite the issue
   number), or (c) skip - not real or by-design and documented.

The verification subagent must not modify files and must not create/close/
edit issues itself. Like steps 3 and 4, it reads `docs/helpers/`
(faq.md, decisions.md) first. Only findings that pass verification (real +
untracked) are offered to the user / created.

**Then ask:** "Create GitHub issue(s) for these findings?"

- If yes, create an issue via `gh` (adjust labels to the project's conventions):

```bash
gh issue create \
  --title "<short title of the discovered problem>" \
  --body "## Description

<what was found>

## Where

- <file:line>

## Suggested fix

<short description>" \
  --label bug
```

- Assign `--label bug` for confirmed bugs or `code-quality` / `enhancement`
  for improvement candidates. One issue per distinct finding keeps them
  actionable.
- If the user declines or the findings are already tracked, just record the
  outcome and finish.

> **Note:** findings that were already fixed as part of this workflow do not
> need an issue - only newly discovered, still-open problems should be
> reported.

---

## Proof of Work (docs/proof_of_work/)

Every cycle leaves a verifiable trail. The **narrative** — context, plan,
coder report, review rounds, CI triage — lives in **PR comments**, generated
from harness artifacts by `bin/pow.php` and never authored by the
orchestrator. The **durable** part is committed:

- `docs/proof_of_work/<NNNN>-<slug>/manifest.json` — machine facts: issue,
  branch, profile, `round_cap`, `rounds[]` (with `comment_id`,
  `comment_sha256`, `prev`, server-assigned `created_at`), `commits[]`,
  `files_changed[]`, `lint_exit`, `test_exit`, `coverage`,
  `findings{total,round1,escaped,open}`, `gates_added[]`, `aborted[]`,
  `verdict`
- `docs/proof_of_work/<NNNN>-<slug>/findings.md` — the append-only ledger
- `docs/proof_of_work/<NNNN>-<slug>/escalation.md` — only when the round cap
  forced an oracle verdict

`docs/proof_of_work/current/` is a gitignored scratch buffer for the cycle in
progress; `.abandoned/<ts>/` keeps cycles that were never finished.

**Command map:**

| Step | Command |
| --- | --- |
| 2.5 | `php bin/pow.php --start --issue=<NUMBER>` |
| 3, 4, 6 | `php bin/pow.php --round=<N> --role=<coder\|review\|oracle\|auditor> --run=<runId>` |
| 4 | `php bin/pow.php --finding --id=F-01 --round=<N> --loc=<file:line> --desc="…" --severity=<high\|medium\|low\|nit>` |
| 5 | `php bin/pow.php --resolve --id=F-01 --round=<N> --status=<fixed\|gated\|wontfix> --resolution="…"` |
| 6 | `php bin/pow.php --verdict=<CLEAN\|NARROW\|REDO\|ACCEPT\|HUMAN>` |
| 7, 11 | `php bin/pow.php --set lint_exit=0 --set test_exit=0 --set coverage=81.4` |
| any | `php bin/pow.php --gate="…"`, `--abort=<runId>:<reason>`, `--status` |
| 11.5 | `php bin/pow.php --finish` (or `--abort --reason="…"`) |

Exit codes: 0 ok, 1 runtime/validation error, 2 usage error. Full reference:
[bin/README.md](../bin/README.md#powphp) and
[proof_of_work/README.md](proof_of_work/README.md).

Do not hand-edit `manifest.json` or `findings.md` — every field the script
writes is derived from something checkable (an artifact on disk, a GitHub
comment, `git log`, the ledger). Hand-writing them turns evidence back into
prose.

---

## Proof-of-work gate (bin/check-pow.php)

A convention the model may skip under pressure to finish is not a process.
`bin/check-pow.php` is the enforcement half of `bin/pow.php`: it re-derives
every claim from a fact somebody else assigned — a GitHub comment body and its
server-side timestamps, a harness artifact on disk, `git show` of an earlier
commit, a recomputed exit code.

The goal is **not** cryptographic impossibility: an orchestrator with a shell
can write any file. The goal is that cheating costs more than doing the work
and leaves a trace in the diff.

### Where it runs

| Where | Invocation | Effect |
| --- | --- | --- |
| `composer lint` | `php bin/check-pow.php` | advisory — reports, exits 0 unless there is evidence of tampering |
| pre-push hook | `php bin/check-pow.php` | **warns always, blocks only** on `^(fix\|feat\|process)/issue-<N>` |
| CI (`pow` job) | the **`origin/master` copy**, `--strict --verify-reality` | **hard gate** — this is the one that decides |

The pre-push hook deliberately does not block every push. A hook that blocks
every push is a hook people bypass with `--no-verify`, which would make the
whole gate fiction. The hard gate is CI.

The CI job is skipped while the PR is a draft (steps 2.5–8 legitimately have no
finished proof of work) and runs from step 9 onward. Expect it to be **red
between step 9 and step 11.5** — the proof of work is the last commit of the
cycle, by design.

### Skip or enforce

The gate **enforces** when the branch matches `^(fix|feat|process)/issue-<N>`
or when the diff touches a protected path. Everything else — another branch, no
pull request for the branch, `gh` missing/unauthenticated/offline — is a
one-line skip with exit 0, so `composer lint` never breaks for ordinary work.
`--strict` turns every "cannot determine" into a failure, because in CI an
unreadable fact is indistinguishable from a hidden one.

Findings come in four severities: `FAIL` (evidence of tampering, always fails),
`PENDING` (the cycle is not finished yet), `UNKNOWN` (a fact could not be read)
and `NOTE`. `PENDING` and `UNKNOWN` only fail under `--strict`.

### Failure modes and how to fix them

| Id | What it means | Fix |
| --- | --- | --- |
| `POW-01` | the PR has no `closingIssuesReferences` — **no work without an issue** | `gh pr edit --body "Closes #<N> …"`, or file the issue first (step 1) |
| `POW-02` | no `docs/proof_of_work/<NNNN>-<slug>/` for the closed issue | run step 11.5: `php bin/pow.php --finish`, commit `docs/proof_of_work` |
| `POW-03` | the manifest is incomplete for its profile: too few rounds, an `open` ledger entry, no verdict, or a non-`CLEAN` verdict with no `escalation.md` | resolve the remaining findings (step 5), record the verdict (step 6), then `--finish` again |
| `POW-04` | something other than `.gitkeep` from `docs/proof_of_work/current/` is in the diff | `git rm --cached` it; `current/` is a gitignored scratch buffer, only `--finish` publishes |
| `POW-05` | the comment chain is broken: a comment is gone, its body no longer hashes to `comment_sha256`, it was edited (`updated_at != created_at`), `prev` does not point at the previous round, or `created_at` does not increase | do not edit or delete round comments. A round that must be replaced is recorded, not rewritten: `php bin/pow.php --abort=<runId>:<reason>` and publish a new round |
| `POW-06` | `findings.md` at one commit is not a byte prefix of the next — a finding was edited or deleted | restore the removed rows; a status change is a NEW row (`php bin/pow.php --resolve`), never an edit |
| `POW-07` | a `coder`/`review` run inside the branch time window appears neither in `rounds[]` nor in `aborted[]` — a **silent re-roll** | `php bin/pow.php --abort=<runId>:"<why it was thrown away>"`. Re-running review until it says "clean" is the cheat this exists for |
| `POW-08` | `manifest falsified` — the declared `lint_exit`/`test_exit`/`coverage` does not match the recomputed value | run the real thing and record the real numbers: `php bin/pow.php --set lint_exit=<code> --set test_exit=<code> --set coverage=<pct>` |
| `POW-09` | a `no-pow` bypass without approval or without a changelog entry | see below |
| `POW-10` | a protected path was touched from the wrong branch or without approval | see below |

### Protected paths

A diff touching `bin/pow.php`, `bin/check-pow.php`, `.github/workflows/*` or
the `scripts` block of `composer.json` changes the rules of the game rather
than the code, so it requires **both**:

1. a `process/` branch prefix (`bin/gh-branch <NUMBER> process`), and
2. an explicit maintainer approval on the pull request.

The branch half fails immediately and on any branch — including branches the
gate would otherwise skip. The approval half is a hard failure in CI
(`--strict`) and a warning locally, because an approval does not exist yet
while the change is still being written.

An unrelated edit to `composer.json` is not a protected change: the gate
compares the decoded `scripts` block against the base ref.

### The gate runs from `master`

A pull request must not be able to weaken the gate that judges it, so CI never
runs the in-tree copy:

```bash
git show origin/master:bin/check-pow.php > "$RUNNER_TEMP/check-pow.php" \
  && php "$RUNNER_TEMP/check-pow.php" --strict --verify-reality --pr=<n> --branch=<name>
```

`php bin/check-pow.php --self-check` does the same locally. When
`origin/master:bin/check-pow.php` does not exist yet — the pull request that
introduces the gate — both fall back to the in-tree copy with a loud notice.

### Escape hatch: the `no-pow` label

Release PRs and reverts have no cycle to prove. The label `no-pow` skips
`POW-01`–`POW-08`, and it costs three things:

- a **maintainer approval** on the PR (checked, not assumed),
- an entry in `docs/process-changelog.md` naming the PR or the issue,
- a loud banner in every CI log that used it.

A bypass is a documented exception, never a silent one. It is not a way to
avoid the work; it is a way to make skipping the work visible.

> **Never weaken the gate to pass it.** Lowering the coverage floor, disabling
> a linter rule or relaxing a check in `bin/check-pow.php` to make a cycle look
> clean is forbidden outright (`docs/helpers/decisions.md`). That is what the
> protected-path rule and the master-copy invocation exist to make expensive.

Full reference: [bin/README.md](../bin/README.md#check-powphp).

---

## Knowledge Base (docs/helpers/)

`worker`/`coder` (implementation) and `review` (code review) subagents
maintain a persistent knowledge base in `docs/helpers/` so lessons learned
carry over to future tasks:

- `docs/helpers/faq.md` — frequently asked questions, recurring pitfalls
  (test daemon ports, pre-push lint hook, coverage gate, `gh` default
  limits) and their solutions
- `docs/helpers/decisions.md` — important project decisions with rationale
  (response strategy, security policy, coverage floor)
- `docs/helpers/README.md` — structure and rules for the knowledge base

Subagents **read** the knowledge base before starting a task and **append**
short entries after finishing (one topic: the problem, the
solution/decision, optionally an issue/commit reference). Entries are
committed as part of the regular fix/feat commits — no extra PRs. In doubt,
extend `docs/troubleshooting.md` or ask the user before adding a new entry.

---

## Quick Reference – Full Cycle

```bash
# 1. Pick an issue — fast path: rank candidates, then let the LLM/user choose
#    php bin/pick-issue.php            # top 5 of lowest milestone (exit 3 => RELEASE NEEDED, stop!)
#    php bin/pick-issue.php --milestone=0.7.0 --top=5 --json
#    alternative: delegate full triage to a subagent ("List top 5 impactful\u2026")

# 2. Feature branch — name derived by the helper (type from labels/prefix)
bin/gh-branch <NUMBER>            # e.g. fix/issue-491-update-readme
#    workflow/tooling changes MUST use: bin/gh-branch <NUMBER> process
branch=$(bin/gh-branch <NUMBER>)  # capture name for the subagent task below

# 2.5 Draft PR first, then open the proof-of-work cycle
git push -u origin "$branch"
gh pr create --draft --title "feat: <desc> (closes #<NUMBER>)" --body "Closes #<NUMBER>" --base master
php bin/pow.php --start --issue=<NUMBER>     # profile from the branch prefix (full 4 / light 2)

# 3. Implementation (worker/coder subagent)
#    subagent: "Implement issue #<NUMBER>..."
#    report must include: files changed, BIGGEST problem, discovered bugs
#    / places to improve (also out of scope)
git add -A && git commit -m "feat: implement <desc> (closes #<NUMBER>)"
git push origin feat/issue-<NUMBER>-<description>
php bin/pow.php --round=1 --role=coder --run=<runId>       # artifact -> PR comment, verbatim

# 4. Code review (subagent) — walks the ledger first, then looks for new issues
php bin/pow.php --round=1 --role=review --run=<runId>
php bin/pow.php --finding --id=F-01 --round=1 --loc=<file:line> --desc="..." --severity=high

# 5-6. Fix, resolve, re-review — HARD CAP: 4 rounds (full) / 2 (light)
php bin/pow.php --resolve --id=F-01 --round=2 --status=fixed --resolution="..."
php bin/pow.php --round=2 --role=review --run=<runId>
php bin/pow.php --verdict=CLEAN
#    at the cap: oracle writes escalation.md and picks NARROW | REDO | ACCEPT | HUMAN
#    ACCEPT must justify EVERY open finding by ID or pow.php rejects it

# 7. Run linters and tests locally, then record the exit codes
composer lint && composer test          # composer lint also runs check-pow (advisory)
php bin/pow.php --set lint_exit=0 --set test_exit=0
#    CI recomputes these and fails the `pow` job as "manifest falsified" on a mismatch

# 8. Update CHANGELOG.md

# 9. Fill in the PR body and take it out of draft
gh pr edit --title "feat: <desc> (closes #<NUMBER>)" --body "..."
gh pr ready

# 10-11. CI
gh pr checks --watch
# ... if failures → record as a finding, fix, review, push → wait for CI (repeat)

# 11.5 Close the proof of work and commit it
php bin/pow.php --finish
git add docs/proof_of_work && git commit -m "docs(pow): proof of work for #<NUMBER>" && git push

# 12. Merge
gh pr merge --squash --delete-branch
gh issue close <NUMBER>

# 13. Switch back to master
git checkout master && git pull origin master

# 14. Report + offer GitHub issue for discovered problems
#    show: biggest problem(s), discovered bugs / places to improve
#    verify each candidate with a review subagent (finding is real?
#    no duplicate on GitHub? use --limit >30 in issue lists)
#    then ask: "Create GitHub issue(s)?" → if yes: gh issue create ...
```

---

## Subagent Usage Summary

Five steps of this workflow are delegated to subagents to keep the main
session's context lean. Every subagent run leaves an artifact in
`.pi-subagents/artifacts/`, and that artifact — not a summary of it — is what
`bin/pow.php --round` publishes:

| Step | Subagent task                              | Why delegate                          |
| ---- | ------------------------------------------ | ------------------------------------- |
| 1    | Triage open issues, return ranked shortlist | Issue bodies + comments are token-heavy |
| 3    | Implement the issue (worker/coder)         | Coding context is token-heavy; agent returns structured report (files, biggest problem, discovered bugs) |
| 4, 6 | Code review of the implementation diff, ledger first | Full diff + surrounding code is token-heavy; the review must confirm or reject every open ledger entry before hunting for new ones |
| 6    | `oracle` at the round cap: pick one binding verdict (`NARROW`/`REDO`/`ACCEPT`/`HUMAN`) and write `escalation.md` | A loop that has not converged in 4 rounds needs a decision from a fresh context, not another iteration |
| 14   | Verify candidate findings before creating GitHub issues (read-only: is the finding real? is it already tracked?) | GitHub duplicate search (open + closed, `--limit` > 30) plus code verification across several findings is query-heavy |

All subagents have read/write/edit/bash tools and operate on the same
repository (the step-14 verifier and the step-6 oracle are instructed to run
read-only). Give each one a clear, scoped instruction and a defined output
format (ranked list with rationale / numbered findings list with
`ID | file:line | description | severity` / coder report with biggest problem
+ discovered bugs / one verdict plus per-finding justification).

Capture the `runId` of every run: it is the prefix of
`.pi-subagents/artifacts/<runId>_<agent>_0_output.md` and the only way
`bin/pow.php --round` can prove the round happened. Runs that were thrown
away are recorded with `--abort=<runId>:<reason>`, never silently dropped.

**Knowledge base:** implementation and review subagents read
`docs/helpers/` before starting and append learnings after finishing
(see "Knowledge Base (docs/helpers/)" above).

---

## Notes

- **gh** must be configured and authenticated (`gh auth status`).
- Branch protection on `master` requires:
  - **at least 1 approving review** before merge
  - All status checks passing (lint, tests)
  - Branch up-to-date with master (recommended)
- Pre-push hook automatically runs `composer lint` and the proof-of-work
  gate (`php bin/check-pow.php`) before each push. The gate warns always and
  blocks only on an issue branch; the hard gate is CI. To skip:
  `git push --no-verify`
- Keep feature branches short-lived. If a rebase is needed:
  ```bash
  git fetch origin master
  git rebase origin/master
  git push --force-with-lease origin feat/issue-<NUMBER>-<description>
  ```
- Code review via subagent runs locally – the subagent has access to
  read/write/edit/bash tools. Give it clear instructions on what to check.
- **Lowering a gate is never an option.** Dropping the coverage floor,
  disabling a linter rule or relaxing a PHPStan level to make a round look
  clean is forbidden — a metric improved by weakening its own check measures
  nothing. Those are `HUMAN` decisions.
- `docs/proof_of_work/current/` is gitignored: only the finished
  `<NNNN>-<slug>/` directory produced by `bin/pow.php --finish` is ever
  committed. The whole tree carries `export-ignore`, so it is not part of the
  distributed package.
