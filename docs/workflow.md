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

> Why the script runs before the subagent, not instead of it — and why that
> choice is rejected as a permanent one for the fallback triage path:
> `docs/process-notices.md` (N-09).

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
instead of from step 9. Round comments go on the **pull request**, not the
issue (`docs/process-notices.md`, N-12), and only the durable machine facts —
never the round narrative itself — get committed (N-11).

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
| `full` | `fix`, `feat`, `refactor`, `perf`, `process` | **4** | mandatory (steps 4b/4c) |
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
#  Read docs/helpers/ first, via the TAG INDEX: load the index at the top of
#  faq.md / decisions.md, pick the tags matching the files you will touch, and
#  read only those entries — never the whole file.
#  You do NOT write to docs/helpers/; propose candidate entries in your report.
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
#  Read docs/helpers/ first, via the TAG INDEX (index + only the entries whose
#  tags match the files in the diff), and flag any violations of documented
#  decisions by entry id. You do NOT write to docs/helpers/ — propose
#  candidate entries in your report.
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

Anything non-obvious the review learned is reported as a **candidate**
knowledge-base entry (title, tags, trigger, one paragraph). The review does not
write to `docs/helpers/` — only the retro step does, see
"Knowledge Base (docs/helpers/)" below.

> **Why a subagent:** code review reads the full diff plus surrounding code,
> runs static analysis, and produces a structured findings list. Delegating
> keeps the main session focused on fixes and the next workflow step.

---

## 4b. Classify Findings for the Gate (reviewer, read-only)

**Who:** the `reviewer` agent (project-scoped, read-only — it does not fix
anything). **What:** for every finding currently in the ledger, answer one
question: *could an automated check have caught this, and which one (a
regression test, a PHPStan rule, a lint rule)?* **When:** after step 4's
review, before findings are fixed in step 5 — classification decides how a
finding gets closed, so it has to happen first.

```bash
# The subagent receives a task like:
# "Read docs/proof_of_work/current/findings.md. For every finding whose
#  effective status is 'open', answer: could an automated check have caught
#  this, and which one? Read docs/helpers/ first (tag index + matching
#  entries only) to check whether this class of defect already has a
#  candidate check on record. Do not fix anything. Do not write to
#  docs/helpers/. Return: ID | classification (gate-candidate / not-automatable)
#  | proposed check | rationale."
```

A finding the reviewer marks as a gate candidate is moved to `gated` when it
is resolved in step 5, instead of merely `fixed`:

```bash
php bin/pow.php --resolve --id=F-02 --round=2 --status=gated \
  --resolution="classified gate-candidate by reviewer — see F-02 in the round comment; test added in the same PR (step 4c)"
```

A finding stays `fixed` (not `gated`) when the reviewer concludes no
automated check would plausibly have caught it (a one-off typo, a
judgement call with no mechanical signature) — classification is a
judgement the reviewer states and justifies, not a rubber stamp on every
finding.

---

## 4c. Escalate to a Gate (coder)

**Who:** the `coder` agent. **What:** for every finding classified `gated` in
step 4b, add the regression test, PHPStan rule or lint rule **in the same
PR** — not a follow-up issue, not a promise for later. **When:** alongside
the fixes in step 5, before the cycle can reach `--verdict=CLEAN`.

**The rule of two:** the **first** occurrence of a class of defect becomes a
knowledge-base candidate (`docs/helpers/faq.md` or `decisions.md`, proposed
in the coder's or reviewer's report — see "Knowledge Base" below); the
**second** occurrence of the *same class* is a mandatory gate. The FAQ is a
buffer for what has been seen once, not a destination for what keeps
happening — an entry that would need a second write-up next time is a gate
that has not been built yet.

Mandatory in the `full` profile (the profile table's "Gate step" column);
**skipped in `light`** — see `docs/process-notices.md` (N-06) for the
measurable condition under which that exemption would be revisited.

```bash
# The subagent receives a task like:
# "docs/proof_of_work/current/findings.md has finding(s) classified
#  gate-candidate by the reviewer (step 4b). For each, add the smallest
#  regression test / PHPStan rule / lint rule that would have caught it,
#  in this PR. If this is the SECOND time this class of defect has been
#  seen (check docs/helpers/faq.md and decisions.md via the tag index —
#  a prior entry for the same class means this occurrence is the second),
#  the gate is mandatory, not optional. Record the gate with
#  php bin/pow.php --gate=\"<one-line description>\"."
```

Record every gate added so `bin/pow-metrics.php` can count it later:

```bash
php bin/pow.php --gate="tests/MarkdownLinkTest.php — internal markdown links must resolve"
```

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
decision, not another iteration. The cap is also the only bound on a single
cycle's proof-of-work size — there is no separate ledger-size limit
(`docs/process-notices.md`, N-10).

At the cap, run the `oracle` subagent in a fresh context. It reads the issue,
the diff and the ledger, and picks **exactly one** binding verdict — never an
automatic `NARROW` (`docs/process-notices.md`, N-01):

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
# Run all linters (php-cs-fixer dry-run, phpstan, rector dry-run), the
# report-only proof-of-work gate (php bin/check-pow.php --advisory) and the
# knowledge-base linter (php bin/kb-lint.php)
composer lint

# Auto-fix fixable issues (php-cs-fixer, rector, kb-lint --fix)
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
> `master` carries no GitHub branch protection (solo maintainer, single
> collaborator) — CI passing plus the maintainer's own decision is what gates
> the merge. See `docs/process-notices.md` (N-13).

---

## 10. Wait for CI

```bash
# Check PR status
gh pr view --json statusCheckRollup

# Wait for all checks to finish
gh pr checks --watch
```

CI workflow (`.github/workflows/tests.yaml`) has six jobs. The proof-of-work
gate is split in two, along the severity line `bin/check-pow.php` already
draws (see [Proof-of-work gate](#proof-of-work-gate-bincheck-powphp)):

1. **lint** – `composer validate --strict`, `composer audit`, then
   `composer lint` (php-cs-fixer, phpstan, rector dry-run, the report-only
   proof-of-work gate `check-pow.php --advisory`, and `bin/kb-lint.php`)
2. **pow** – the `origin/master` copy of `bin/check-pow.php` in its
   **default** mode (no `--strict`): only a `violation` — evidence of
   tampering — is fatal, an unfinished cycle is not. That makes it meaningful
   on a draft too, so it runs on every push from the first one, with no
   `needs:`, and finishes in seconds
3. **tests matrix** (PHP 8.2–8.5 × Symfony 6.4–8.0) – unit + E2E tests;
   `needs: [lint, pow]`, so tampered evidence stops the whole matrix before
   nine legs run. The PHP 8.2 / Symfony 6.4 leg also enforces the
   line-coverage floor
4. **pow-reality** – the hard merge gate: the `origin/master` copy of
   `bin/check-pow.php` with `--strict --verify-reality`, `needs: [lint, tests]`
   and skipped while the PR is a draft (a draft's proof of work is legitimately
   incomplete until step 11.5, and this is the expensive check — recomputing
   lint/tests/coverage — so it waits for `tests` to be green rather than racing
   it for runners)
5. **benchmark** – advisory; CI runner timing varies too much to gate merges
   on it
6. **ci** – aggregator: fails unless `lint` and `tests` succeeded, and `pow` /
   `pow-reality` each either succeeded or were skipped (the draft skip)

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

> **Note:** The pre-push hook runs `composer lint` before every push, which
> includes the proof-of-work gate in report-only mode. On an issue branch
> (`^(fix|feat|refactor|perf|process)/issue-<N>`) it then runs the gate once more, and there
> evidence of tampering blocks the push; an unfinished cycle does not.
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

> **Note:** `master` has no branch protection, so `gh pr merge` is blocked only
> by CI (`ci` must be green) — there is no review to obtain. See
> `docs/process-notices.md` (N-13) for what that does and does not buy.

---

## 13. Switch Back to master

```bash
git checkout master
git pull origin master
```

---

## 13.5. Audit the Proof of Work (reviewer, fresh context)

**Who:** the `reviewer` agent, in a **fresh context** — no memory of this
cycle's chat, no access to anything the orchestrator said along the way.
**What:** the only inputs are the committed proof of work
(`docs/proof_of_work/<NNNN>-<slug>/`), `git log --format=fuller` for the
merged commits, and the diff. The question is exactly: *does the evidence
support that the flow ran as documented? List inconsistencies.* **When:**
after the merge (step 12) and the branch switch (step 13) — the audit looks
at the finished, merged record, not a cycle still in flight.

```bash
# The subagent receives a task like:
# "Fresh context. You have not seen this cycle before. Read:
#  - docs/proof_of_work/<NNNN>-<slug>/manifest.json and findings.md
#    (and escalation.md if present)
#  - git log --format=fuller <base>..<merge-commit> for the merged branch
#  - the diff introduced by the merge
#  Does the evidence support that the workflow in docs/workflow.md actually
#  ran as documented? List every inconsistency you find: a round that
#  claims more happened than the diff shows, a finding resolved with no
#  matching code change, a verdict that does not match the ledger state,
#  timestamps that do not make sense. Do not fix anything. Do not write to
#  docs/helpers/. Report format: PASS/inconsistencies-found, then one line
#  per inconsistency with evidence."
```

**Always on in the `full` profile** — there is deliberately **no
"only from 3+ rounds" threshold**. A round-count trigger is something the
orchestrator can see and route around: an incentive to look done by round 2
just to skip the one check designed to catch a convincing-but-false
narrative would defeat the audit before it ever ran. Fresh context is the
whole point — it is what makes the audit resistant to being talked around by
how the cycle was framed, and that resistance only holds if it is not
conditional on a number the orchestrator controls. See
`docs/process-notices.md` (N-02) for the measurable condition under which
this would be revisited, and (N-08) for why the audit's inputs stay narrow
(POW + git log + diff) rather than re-deriving acceptance criteria from the
issue body.

An inconsistency found here does not reopen the merged PR; it is either
folded into step 14 (if it is a real, still-open code problem) or becomes a
candidate for step 15's retro (if it points at a process gap — e.g. a step
that is easy to skip without anyone noticing).

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
edit issues itself. Like steps 3 and 4, it reads `docs/helpers/` first — tag
index plus the entries matching the files in the diff — and writes nothing
there. Only findings that pass verification (real + untracked) are offered to
the user / created.

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

## 15. Retro (every ~5 cycles)

Steps 1–14 (plus 13.5) run every cycle. The retro runs on a coarser cadence —
roughly every 5 finished cycles — because it needs enough manifests to see a
pattern rather than react to one unlucky cycle.

```bash
php bin/pow-metrics.php --since=5 --min-cycles=3
```

`--min-cycles=3` is the guardrail, not a suggestion: with fewer than 3
manifests in scope the script itself exits non-zero, so the retro cannot run
on thin evidence. Run it before starting 15a.

Before diagnosing, skim [`docs/process-notices.md`](process-notices.md) — the
registry of alternatives already considered and rejected, each with a
measurable trigger for reopening it. A proposal in 15a that matches an entry
there without its trigger having fired is not a new diagnosis, it is
re-litigating a settled question; one that matches an entry whose trigger
*has* fired is exactly the kind of evidence-backed change 15a exists to
surface.

### 15a. Diagnose (oracle, fork)

**Who:** the `oracle` agent, in a **fork** of the metrics output — no other
context. **What:** diagnose over the metrics from `bin/pow-metrics.php`
(≥3 manifests) and propose **at most 2** changes. Proposing more than 2 per
retro is itself a smell: a retro that finds ten things wrong at once did not
diagnose, it vented.

Every proposed change is classified into exactly one of:

| Class | Meaning | Where it lands |
| --- | --- | --- |
| `automation` | a check, script or gate would prevent recurrence | GitHub issue, `process` label, current milestone |
| `workflow` | `docs/workflow.md` itself needs to change (a step, an order, a threshold) | GitHub issue, `process` label, current milestone |
| `knowledge` | worth recording, not worth automating yet | committed directly to `docs/helpers/` (step 16) |
| `noop` | no action — see the guardrail below | recorded in the oracle's report only |

```bash
# The subagent receives a task like:
# "Fork. Your only input is this: <output of bin/pow-metrics.php --since=5>.
#  Diagnose at most 2 changes that would measurably improve the numbers
#  above. For each: what changed metric or repeated pattern motivates it,
#  the proposed change, its class (automation/workflow/knowledge/noop), and
#  a measurable success criterion (docs/process-changelog.md's format).
#  Guardrail: propose a change ONLY when you can cite either a specific
#  metric from the input above, or >=2 recorded occurrences of the same
#  issue (cite both). No such evidence -> classify it noop and say so
#  explicitly; do not propose a change on a hunch."
```

**Guardrail:** no evidence — a metric from `bin/pow-metrics.php`, or **≥2**
recorded occurrences of the same class of problem — means `noop`. A retro
that always finds 2 changes to make is not diagnosing, it is producing
theater; `noop` is a legitimate, expected outcome of most retros.

### 15b. Verify (reviewer, fresh, read-only)

**Who:** the `reviewer` agent, in a **fresh, read-only** context — it does
not see the oracle's reasoning, only its conclusions and the same metrics
input. **What:** independently check whether the cited evidence actually
supports each proposed change, so the oracle cannot rubber-stamp its own
conclusions by construction (nobody grades their own diagnosis).

```bash
# The subagent receives a task like:
# "Fresh context, read-only. Here is a proposed process change and the
#  metrics/evidence cited for it: <oracle's 15a output>. Independently
#  verify: does the cited metric or occurrence count actually exist and
#  support the claim? Is 'noop' the more honest classification? Do not
#  fix or apply anything; do not write to docs/helpers/. Report: for each
#  proposed change, CONFIRMED / DOWNGRADE-TO-NOOP / DISPUTED, with the
#  evidence you checked."
```

A change downgraded to `noop` by 15b does not proceed to step 16. A `DISPUTED`
change is a `HUMAN` call, same as an unresolved oracle verdict at the round
cap (step 6) — ask the user rather than picking a side.

---

## 16. Apply (coder)

**Who:** the `coder` agent. **What:** apply the outcome of steps 15a/15b.
**When:** immediately after 15a/15b conclude a change is neither `noop` nor
`DISPUTED` — a `knowledge` outcome is committed in the same session;
`automation`/`workflow` outcomes are filed as GitHub issues then, with the
`docs/process-changelog.md` entry following once that issue's own PR merges.

- **`knowledge`** changes are committed **immediately** — the retro step is
  the knowledge base's single writer (`docs/helpers/README.md`, `DEC-009`).

  > **Cycle-zero note, not standing policy:** for phase 4 of issue #686 —
  > the PR that introduced steps 15/16 themselves — "the retro step" was the
  > agent that wrote this document, since no earlier retro could have run
  > the process before it existed. `docs/process-changelog.md` entry #1
  > records the analogous cycle-zero exemption for the proof-of-work format
  > as a whole; this is the same kind of one-time exception, not a second
  > instance of it. Every retro after this one runs steps 15a/15b/16 as
  > separate subagent invocations exactly as described above — this note
  > does not relax that.
- **`automation`** and **`workflow`** changes become GitHub issues labelled
  `process`, filed into the **current milestone** (not backlog-and-forget —
  `bin/pick-issue.php` will surface them like any other issue):

```bash
gh issue create \
  --title "process: <short description of the automation/workflow change>" \
  --body "## Diagnosed by

Retro over docs/proof_of_work/ cycles <range> (bin/pow-metrics.php --since=5)

## What

<the proposed change>

## Why

<the metric or >=2 occurrences that motivated it>

## Success criterion

<measurable, from docs/process-changelog.md's format>" \
  --label process --milestone <current milestone>
```

- Every applied change — `knowledge` immediately, `automation`/`workflow`
  once their issue's own PR merges — gets one entry in
  `docs/process-changelog.md`, with `Outcome: pending`. Step 17 is what
  fills that in; nothing here marks a change `kept` on day one.

---

## 17. Verify the Change Stuck (delegate)

**Who:** the `delegate` agent. **When:** after **5 cycles** have passed since
a process change's `docs/process-changelog.md` entry was recorded — same
cadence as the retro itself, offset so a change has time to show up in the
metrics before it is judged.

**What:** for each entry still `pending`, check its success criterion against
`bin/pow-metrics.php` output and the repository, then record `kept` or
`reverted` (never leave it `pending` past its review point — an unresolved
verdict here is a process change nobody checked, which is a silent failure
mode identical to the one the whole retro loop exists to close).

```bash
# The subagent receives a task like:
# "For every entry in docs/process-changelog.md still marked
#  Outcome: pending whose Date is >=5 cycles old (cross-reference
#  bin/pow-metrics.php --json for cycle count), check its Success
#  criterion against the current metrics/repository state. Append the
#  verdict: kept (criterion met, keep the change) or reverted (criterion
#  not met — and say what should happen to the change itself: is it
#  reverted outright, or does it need its own follow-up issue?).
#  Do not silently leave an entry pending past its review point."
```

**Why this step exists, stated plainly:** without it, the loop is
self-*mutating*, not self-improving — steps 15/16 can change the process
based on a plausible diagnosis, but nothing ever comes back to check whether
that diagnosis was right. A change that turned out to make things worse
would simply sit there, indistinguishable from one that helped, forever.
Step 17 is the difference between "we changed something" and "we learned
something."

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
| 3, 4, 6 | `php bin/pow.php --round=<N> --role=<coder\|review> --run=<runId>` (the oracle at the cap writes `escalation.md` and calls `--verdict=` directly — it has no `--round` of its own) |
| 4 | `php bin/pow.php --finding --id=F-01 --round=<N> --loc=<file:line> --desc="…" --severity=<high\|medium\|low\|nit>` |
| 5 | `php bin/pow.php --resolve --id=F-01 --round=<N> --status=<fixed\|gated\|wontfix> --resolution="…"` |
| 6 | `php bin/pow.php --verdict=<CLEAN\|NARROW\|REDO\|ACCEPT\|HUMAN>` |
| 7, 11 | `php bin/pow.php --set lint_exit=0 --set test_exit=0 --set coverage=81.4` |
| 4c | `php bin/pow.php --gate="…"` |
| any | `--abort=<runId>:<reason>`, `--status` |
| 11.5 | `php bin/pow.php --finish` (or `--abort --reason="…"`) |
| 15 | `php bin/pow-metrics.php --since=5 --min-cycles=3 [--json]` |

Exit codes: 0 ok, 1 runtime/validation error, 2 usage error. Full reference:
[bin/README.md](../bin/README.md#powphp) and
[proof_of_work/README.md](proof_of_work/README.md); `bin/pow-metrics.php` is
documented in [bin/README.md](../bin/README.md#pow-metricsphp).

Do not hand-edit `manifest.json` or `findings.md` — every field the script
writes is derived from something checkable (an artifact on disk, a GitHub
comment, `git log`, the ledger). Hand-writing them turns evidence back into
prose.

---

## Proof-of-work gate (bin/check-pow.php)

A convention the model may skip under pressure to finish is not a process.
`bin/check-pow.php` is the enforcement half of `bin/pow.php`: it re-derives
every claim from a fact somebody else assigned — a GitHub comment body and its
server-side timestamps (chosen over commit signing as the tamper-evidence
mechanism; `docs/process-notices.md`, N-07), a harness artifact on disk,
`git show` of an earlier commit, a recomputed exit code.

The goal is **not** cryptographic impossibility: an orchestrator with a shell
can write any file. The goal is that cheating costs more than doing the work
and leaves a trace in the diff.

### Where it runs

The gate is split into two CI jobs along the severity line it already draws
(the table below), plus two other places it runs:

| Where | Invocation | Effect |
| --- | --- | --- |
| `composer lint` | `php bin/check-pow.php --advisory` | report only — always exits 0 |
| pre-push hook | `php bin/check-pow.php`, **only** on `^(fix\|feat\|refactor\|perf\|process)/issue-<N>` | blocks on evidence of tampering; an unfinished cycle does not block |
| CI (`pow` job) | the **`origin/master` copy**, **default mode** (no `--strict`) | only a `violation` (evidence of tampering) is fatal; runs on every push, drafts included, with no `needs:` |
| CI (`pow-reality` job) | the **`origin/master` copy**, `--strict --verify-reality` | **hard gate** — this is the one that decides, once the PR is out of draft |

`composer lint` runs the gate in `--advisory` mode on purpose. Composer aborts
an array script on the first non-zero command, so a gate that can fail inside
`lint` blocks every push on every branch — including branches nobody is running
a cycle on. `lint` stays the canonical entry point (`DEC-008`) and stays green;
the hook adds the one blocking run where a proof of work is actually expected.

The pre-push hook deliberately does not block every push. A hook that blocks
every push is a hook people bypass with `--no-verify`, which would make the
whole gate fiction. The hard gate is CI (rejected alternative: making the hook
itself the hard block — `docs/process-notices.md`, N-03).

`pow` runs in default mode rather than `--strict` specifically so it is
meaningful on a draft: an unfinished cycle (steps 2.5–8 legitimately have no
finished proof of work yet) is not fatal there, only tampering is. `tests`
depends on it (`needs: [lint, pow]`), so tampered evidence stops the whole
matrix before nine legs run. `pow-reality` is the one that is skipped while
the PR is a draft and runs from step 9 onward — expect **it**, not `pow`, to be
red between step 9 and step 11.5, since the proof of work is the last commit
of the cycle by design.

### Skip or enforce

The gate **enforces** when the branch matches `^(fix|feat|refactor|perf|process)/issue-<N>`
or when the diff touches a protected path. Everything else — `master`/`main`
(a base branch is never gated against itself), another branch, no pull request
for the branch, `gh` missing/unauthenticated/offline — is a one-line skip with
exit 0. `--strict` turns every "cannot determine" into a failure, because in CI
an unreadable fact is indistinguishable from a hidden one — including a `git`
call that exited non-zero, which is reported rather than read as "no changes".

Findings come in four severities: `FAIL` (evidence of tampering, always fails),
`PENDING` (the cycle is not finished yet), `UNKNOWN` (a fact could not be read)
and `NOTE`. `PENDING` and `UNKNOWN` only fail under `--strict`.

### Failure modes and how to fix them

| Id | What it means | Fix |
| --- | --- | --- |
| `POW-00` | meta: a needed fact could not be read (the changed-file list, or a pull request to validate) — `UNKNOWN`, fails only under `--strict`; also the id of the lone `NOTE` printed when every other check passes | usually a shallow clone or an unauthenticated `gh`; `--strict` will say which |
| `POW-01` | the PR has no `closingIssuesReferences` — **no work without an issue** | `gh pr edit --body "Closes #<N> …"`, or file the issue first (step 1) |
| `POW-02` | no `docs/proof_of_work/<NNNN>-<slug>/` for the closed issue, or its `manifest.json` names a different `issue`/`branch` or an unknown `pow_version` | run step 11.5: `php bin/pow.php --finish`, commit `docs/proof_of_work`. Renaming an older cycle's directory does not rebind it — the manifest is the binding |
| `POW-03` | the cycle is incomplete for the profile it is **entitled** to (re-derived from the branch prefix and the issue labels, never read from the manifest): too few rounds, a malformed ledger row, an `open` entry, a missing `lint_exit`/`test_exit`, no verdict, or a non-`CLEAN` verdict with no `escalation.md` naming every open finding | resolve the remaining findings (step 5), record the numbers and the verdict (step 6), then `--finish` again |
| `POW-04` | something other than `.gitkeep` from `docs/proof_of_work/current/` is in the diff | `git rm --cached` it; `current/` is a gitignored scratch buffer, only `--finish` publishes |
| `POW-05` | the comment chain is broken: a comment is gone, its body no longer hashes to `comment_sha256`, it was edited (`updated_at != created_at`), `prev` does not point at the previous round, or `created_at` does not increase | do not edit or delete round comments. A round that must be replaced is recorded, not rewritten: `php bin/pow.php --abort=<runId>:<reason>` and publish a new round |
| `POW-06` | `findings.md` at one commit is not a byte prefix of the next — a finding was edited or deleted | restore the removed rows; a status change is a NEW row (`php bin/pow.php --resolve`), never an edit. **Inert in the documented flow** (the ledger is committed once, at step 11.5) — it then says so as a `NOTE`; the real anchor is `POW-05` |
| `POW-07` | a `coder`/`review` run inside the branch time window appears neither in `rounds[]` nor in `aborted[]` — a **silent re-roll** | `php bin/pow.php --abort=<runId>:"<why it was thrown away>"`. Re-running review until it says "clean" is the cheat this exists for. **Local advisory only**: `.pi-subagents/` is gitignored, so it never runs in CI |
| `POW-08` | `manifest falsified` — the declared `lint_exit`/`test_exit`/`coverage` does not match the recomputed value | run the real thing and record the real numbers: `php bin/pow.php --set lint_exit=<code> --set test_exit=<code> --set coverage=<pct>` |
| `POW-09` | a `no-pow` label with no `docs/process-changelog.md` line naming both `no-pow` and the exact number | see below |
| `POW-10` | a protected path was touched from a branch other than `process/` | see below |
| `POW-11` | `CHECK_POW_SKIP` or `CHECK_POW_GH_FIXTURE` was set where the gate ignores it (`--strict`, or a CI runner) | unset it. A kill switch that outranks the gate is not a gate |

### Protected paths

A diff touching `bin/pow.php`, `bin/pow-common.php`, `bin/check-pow.php`,
`.github/workflows/*` or the `scripts` block of `composer.json` changes the
rules of the game rather than the code, so it requires a `process/` branch
prefix (`bin/gh-branch <NUMBER> process`).

> There used to be a second requirement here — a maintainer approval submitted
> after the newest protected-path commit — dropped in #686 phase 5. This
> repository has a single collaborator with write access, and GitHub does not
> allow approving your own pull request, so the requirement was unsatisfiable
> by construction rather than merely strict; it would have deadlocked every
> protected-path change forever. See `docs/process-notices.md` (N-13) for what
> the branch-prefix requirement alone still buys.

This check fails on any branch the gate enforces on — including branches it
would otherwise skip, since touching a protected path is itself what puts the
diff in scope. Untracked files count: a brand-new protected file is seen before
it is committed.

An unrelated edit to `composer.json` is not a protected change: the gate
compares the decoded `scripts` block against the base ref.

### The gate runs from `master`

A pull request must not be able to weaken the gate that judges it, so CI never
runs the in-tree copy — both the `pow` and the `pow-reality` job materialise
the `origin/master` copy the same way, differing only in the flags they invoke
it with (default mode for `pow`, `--strict --verify-reality` for `pow-reality`,
shown here):

```bash
git show origin/master:bin/check-pow.php  > "$gate/check-pow.php" \
  && git show origin/master:bin/pow-common.php > "$gate/pow-common.php" \
  && php "$gate/check-pow.php" --strict --verify-reality --pr=<n> --branch=<name>
```

Both files, from the same ref: `check-pow.php` requires `pow-common.php` from
its own directory, and half a gate from `master` is no gate. When
`origin/master:bin/check-pow.php` does not exist yet — the pull request that
introduces the gate — CI falls back to the in-tree copy with a loud warning.

There is deliberately **no local equivalent**. A `--self-check` flag existed and
was removed: locally `master` is whatever the developer's clone happens to hold,
so it advertised a property it could not deliver. One mechanism, in the place
where the ref is fetched from the remote.

### Escape hatch: the `no-pow` label

Release PRs and reverts have no cycle to prove. The label `no-pow` skips
`POW-01`–`POW-08`, and it costs two things:

- a line in `docs/process-changelog.md` naming **both** `no-pow` and the exact
  PR or issue number — `#700` does not match `#7001`, and a line that merely
  mentions the number is not a record,
- a loud banner in every CI log that used it.

> There used to be a third cost — a maintainer approval — dropped in #686
> phase 5. This repository has one collaborator with write access and GitHub
> does not allow approving your own pull request, so it was unsatisfiable, not
> merely strict. See `docs/process-notices.md` (N-13).

The label by itself buys nothing: until the changelog line exists, the bypass
is **inert** — checks 1–8 run exactly as they would without the label, and
`POW-09` reports a `violation` (not merely pending — writing the changelog
line is entirely within the PR author's own control, there is no external
actor to wait on) naming what is missing. That is deliberate: a label with no
record must not switch off the tamper checks or block a push/the
`pow`-gated test matrix on its own.

A bypass is a documented exception, never a silent one. It is not a way to
avoid the work; it is a way to make skipping the work visible.

> **Never weaken the gate to pass it.** Lowering the coverage floor, disabling
> a linter rule or relaxing a check in `bin/check-pow.php` to make a cycle look
> clean is forbidden outright (`docs/helpers/decisions.md`). That is what the
> protected-path rule and the master-copy invocation exist to make expensive.

Full reference: [bin/README.md](../bin/README.md#check-powphp).

---

## Knowledge Base (docs/helpers/)

A persistent knowledge base so lessons learned carry over to future tasks:

- `docs/helpers/faq.md` — recurring pitfalls and their solutions (test daemon
  ports, pre-push lint hook, coverage gate, `gh` default limits). Ids `FAQ-NNN`.
- `docs/helpers/decisions.md` — project decisions with rationale (response
  strategy, security policy, coverage floor). Ids `DEC-NNN`.
- `docs/helpers/README.md` — entry format, single-writer rule, decay rules

**Read the index, not the file.** Every file opens with a generated **tag
index** mapping tags to entry ids. A subagent loads the index, picks the tags
matching the files in its diff, and reads only those `###` entries. Reading 300
lines of FAQ for a two-file change is exactly what the index exists to prevent.

**One writer.** Only the **retro step (15/16)** writes to `docs/helpers/`.
`coder`/`coder-high` and `review`/`review-critical` **propose** candidate
entries in their report — title, tags, trigger, one paragraph — and the retro
decides what lands. Two writers produced duplicates, unlabelled entries and a
file that had to be read in full (issue #686, `DEC-009`). See "15. Retro" and
"16. Apply" below for the retro step's mechanics — a subagent outside those
two steps that appends to the knowledge base is doing the wrong thing.

**Prefer a gate over an entry.** If a regression test, PHPStan rule or lint rule
could catch the class of defect, add the check. The knowledge base is a buffer
for what cannot be automated yet, not a destination.

Every entry carries single-line front matter (`id`, `date`, `tags`, `trigger`,
`hits`, `status`) in an HTML comment right after its heading.
`php bin/kb-lint.php` validates it, regenerates the tag index (`--fix`), warns
above 300 lines per file and lists `stale` entries; it runs inside
`composer lint`. Full reference:
[docs/helpers/README.md](helpers/README.md) and
[bin/README.md](../bin/README.md#kb-lintphp).

---

## Agent Map

Which agent runs at which step. Agents marked **project-scoped** live in
[`.pi/agents/`](../.pi/agents) inside this repository, so their prompts are
versioned and **changing one now requires a reviewed PR** (loop C of issue
#686) — they are no longer per-machine files in `~/.agents/`. The rest are
still user-scoped.

| Step | Agent | Scope | Role |
| --- | --- | --- | --- |
| 1 | `delegate` | user | triage open issues, return a ranked shortlist |
| 1b | `scout` | **project** | fast recon: relevant files, flows, KB tags to load |
| 1c | `context-builder` | user | compress the issue + code into a handoff brief |
| 2b | `planner` | user | plan the change before any edit |
| 2c | `oracle` | user | judgement call on approach when the plan is contested |
| 3 | `coder` / `coder-high` / `worker` | **project** (`coder`, `coder-high`) | implement; return files changed, biggest problem, discovered bugs, candidate KB entries |
| 4 | `review` / `review-critical` | **project** | ledger-first code review |
| 4b | `reviewer` | **project** | classify findings: which automated check would have caught this? |
| 4c | `coder` | **project** | add the regression test / PHPStan rule / lint rule for every `gated` finding, same PR |
| 11 | `delegate` | user | compress CI logs into actionable failures |
| 13.5 | `reviewer` | **project** | audit the proof of work in a fresh context |
| 14 | `reviewer` | **project** | verify candidate findings before opening GitHub issues |
| 15a | `oracle` | user | retro: diagnose over ≥3 manifests, propose ≤2 classified changes |
| 15b | `reviewer` | **project** | retro: independently verify the oracle's evidence |
| 16 | `coder` | **project** | commit the `knowledge` outcome, file the rest as `process` issues |
| 17 | `delegate` | user | after 5 cycles, check whether earlier process changes stuck |

Steps 4b, 4c, 13.5, 15, 16 and 17 were introduced by phase 4 of issue #686;
see "4b. Classify Findings", "4c. Escalate to a Gate", "13.5. Audit the Proof
of Work", "15. Retro", "16. Apply" and "17. Verify the Change Stuck" above for
their mechanics.

**`review-critical` is mandatory**, not a judgement call, when the diff touches
any of:

- `src/Http`
- security-relevant code or policy
- process supervision (fork/signal handling, master identification, supervisor)
- more than **200 changed lines**
- a public interface (`ResponseConverterStrategyInterface` and friends)

Otherwise `review` is enough.

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

# 4b. Classify (reviewer, read-only): could an automated check have caught this?
#     gate candidates get --status=gated when resolved in step 5
# 4c. Escalate to a gate (coder): add the test/rule for every `gated` finding,
#     in this PR (mandatory in `full`, skipped in `light`) — rule of two:
#     1st occurrence -> KB entry, 2nd occurrence of the same class -> gate
php bin/pow.php --gate="tests/SomeNewGuard.php — what it now catches"

# 5-6. Fix, resolve, re-review — HARD CAP: 4 rounds (full) / 2 (light)
php bin/pow.php --resolve --id=F-01 --round=2 --status=fixed --resolution="..."
php bin/pow.php --resolve --id=F-02 --round=2 --status=gated --resolution="..."
php bin/pow.php --round=2 --role=review --run=<runId>
php bin/pow.php --verdict=CLEAN
#    at the cap: oracle writes escalation.md and picks NARROW | REDO | ACCEPT | HUMAN
#    ACCEPT must justify EVERY open finding by ID or pow.php rejects it

# 7. Run linters and tests locally, then record the exit codes
composer lint && composer test          # lint also runs check-pow (advisory) + kb-lint
php bin/pow.php --set lint_exit=0 --set test_exit=0
#    CI recomputes these and fails the `pow-reality` job as "manifest falsified" on a mismatch

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

# 13.5 Audit the proof of work (reviewer, FRESH context; always on in `full`)
#    inputs: docs/proof_of_work/<NNNN>-<slug>/, git log --format=fuller, the diff
#    "does the evidence support that the flow ran as documented?"

# 14. Report + offer GitHub issue for discovered problems
#    show: biggest problem(s), discovered bugs / places to improve
#    verify each candidate with a review subagent (finding is real?
#    no duplicate on GitHub? use --limit >30 in issue lists)
#    then ask: "Create GitHub issue(s)?" → if yes: gh issue create ...

# 15. Retro — every ~5 cycles, --min-cycles guards against thin evidence
php bin/pow-metrics.php --since=5 --min-cycles=3
#    15a oracle (fork): diagnose, propose <=2 changes, each automation|workflow|knowledge|noop
#    15b reviewer (fresh, read-only): independently verify the oracle's evidence

# 16. Apply (coder)
#    knowledge  -> commit straight to docs/helpers/ (the retro is its single writer)
#    automation/workflow -> gh issue create --label process --milestone <current>
#    either way: one docs/process-changelog.md entry, Outcome: pending

# 17. Verify the change stuck (delegate) — after 5 cycles, check each pending
#     entry's success criterion, record kept | reverted in
#     docs/process-changelog.md
```

---

## Subagent Usage Summary

Most steps of this workflow are delegated to subagents to keep the main
session's context lean. Every subagent run leaves an artifact in
`.pi-subagents/artifacts/`, and that artifact — not a summary of it — is what
`bin/pow.php --round` publishes:

| Step | Subagent task                              | Why delegate                          |
| ---- | ------------------------------------------ | ------------------------------------- |
| 1    | Triage open issues, return ranked shortlist | Issue bodies + comments are token-heavy |
| 3    | Implement the issue (worker/coder)         | Coding context is token-heavy; agent returns structured report (files, biggest problem, discovered bugs) |
| 4, 6 | Code review of the implementation diff, ledger first | Full diff + surrounding code is token-heavy; the review must confirm or reject every open ledger entry before hunting for new ones |
| 4b   | `reviewer` (read-only): classify every finding — could an automated check have caught it, and which one? | A finding worth gating has to be identified before step 5 closes its ledger row, and a fixer should not also be the one deciding whether its own fix needed a gate |
| 6    | `oracle` at the round cap: pick one binding verdict (`NARROW`/`REDO`/`ACCEPT`/`HUMAN`) and write `escalation.md` | A loop that has not converged in 4 rounds needs a decision from a fresh context, not another iteration |
| 13.5 | `reviewer`, **fresh context**: does the evidence (POW + `git log --format=fuller` + diff) support that the flow ran as documented? | Fresh context cannot be talked around by the orchestrator's narrative — the whole point of an audit that checks the process, not the code |
| 14   | Verify candidate findings before creating GitHub issues (read-only: is the finding real? is it already tracked?) | GitHub duplicate search (open + closed, `--limit` > 30) plus code verification across several findings is query-heavy |
| 15a  | `oracle` (fork): diagnose over ≥3 manifests' metrics, propose ≤2 classified changes | A retro needs to look at several cycles at once, in a context that holds only the metrics, not this cycle's narrative |
| 15b  | `reviewer`, **fresh, read-only**: independently verify the oracle's cited evidence | The oracle must not grade its own diagnosis — a second, evidence-blind pass is what makes `noop` a credible outcome, not a rubber stamp |
| 17   | `delegate`: check each pending process-changelog entry's success criterion after 5 cycles | Cross-referencing several changelog entries against `bin/pow-metrics.php` output is exactly the token-heavy compression this section delegates for every other step |

All subagents have read/write/edit/bash tools and operate on the same
repository (the step-4b classifier, the step-6 oracle, the step-13.5 auditor,
the step-14 verifier and the step-15b verifier are instructed to run
read-only). Give each one a clear, scoped instruction and a defined output
format (ranked list with rationale / numbered findings list with
`ID | file:line | description | severity` / coder report with biggest problem
+ discovered bugs / one verdict plus per-finding justification / a change
proposal classified `automation`/`workflow`/`knowledge`/`noop` with cited
evidence).

Capture the `runId` of every run: it is the prefix of
`.pi-subagents/artifacts/<runId>_<agent>_0_output.md` and the only way
`bin/pow.php --round` can prove the round happened. Runs that were thrown
away are recorded with `--abort=<runId>:<reason>`, never silently dropped.

**Knowledge base:** implementation and review subagents read `docs/helpers/`
before starting — the tag index plus the entries matching the files in the diff
— and **propose** candidate entries in their report. They never append: the
retro step is the single writer (see "Knowledge Base (docs/helpers/)" above).

**Agent scope:** `scout`, `coder`, `coder-high`, `review`, `review-critical` and
`reviewer` are project-scoped in [`.pi/agents/`](../.pi/agents); their prompts
are part of the repository and change through a reviewed PR. See
"[Agent Map](#agent-map)".

---

## Notes

- **gh** must be configured and authenticated (`gh auth status`).
- `master` carries **no GitHub branch protection** — this is a solo-maintainer
  project with a single collaborator, and GitHub does not allow approving your
  own pull request, so there is no reviewer to require one from. What actually
  gates a merge: CI (the `ci` aggregator job, itself gated on `lint`, `tests`,
  `pow` and `pow-reality`) plus the maintainer's own decision to merge. See
  `docs/process-notices.md` (N-13) for what that buys and does not buy, and
  the condition for revisiting it.
- Pre-push hook automatically runs `composer lint` before each push, which
  includes the proof-of-work gate in report-only mode. On an issue branch the
  gate runs once more and can block; the hard gate is CI. To skip:
  `git push --no-verify`
- Keep feature branches short-lived. If a rebase is needed:
  ```bash
  git fetch origin master
  git rebase origin/master
  git push --force-with-lease origin feat/issue-<NUMBER>-<description>
  ```
- Code review via subagent runs locally. `coder`/`coder-high` are granted
  read/write/edit/bash; `review`, `review-critical`, `reviewer` and `scout` are
  granted only read/bash — there is nothing to withhold by instruction, they
  simply cannot write or edit. Give each one clear instructions on what to
  check.
- **Lowering a gate is never an option.** Dropping the coverage floor,
  disabling a linter rule or relaxing a PHPStan level to make a round look
  clean is forbidden — a metric improved by weakening its own check measures
  nothing. Those are `HUMAN` decisions.
- `docs/proof_of_work/current/` is gitignored: only the finished
  `<NNNN>-<slug>/` directory produced by `bin/pow.php --finish` is ever
  committed. The whole tree carries `export-ignore`, so it is not part of the
  distributed package.
