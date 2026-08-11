# Workflow: Issue → Feature Branch → Draft PR → Implementation → Review Rounds → CI → Merge

This document describes the complete workflow for handling issues in the
[crazy-goat/workerman-bundle](https://github.com/crazy-goat/workerman-bundle)
repository using `gh` and `git`.

Every cycle leaves a **proof of work** under `docs/proof_of_work/<NNNN>-<slug>/`:
four kinds of Markdown file, written by the agents that did the work and
committed on the branch. See [Proof of Work](#proof-of-work-docsproof_of_work)
below and [proof_of_work/README.md](proof_of_work/README.md).

Nothing enforces them. They are read during review, like the code.

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
labelled `process` therefore lands on a `process/` branch — use that prefix for
changes to the workflow itself (`docs/workflow.md`, `.github/workflows/*`, the
`scripts` block of `composer.json`), so that "we changed the rules" is visible
in the branch name. The branch is created from the fresh remote default branch,
so no manual fetch/pull is needed. If the branch
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

## 2.5 Open the Draft PR

The draft PR is created **right after the branch**, before a single line is
written. It starts CI earlier and makes `closingIssuesReferences` exist from
the first push instead of from step 9.

```bash
git push -u origin "$branch"          # an empty branch is enough
gh pr create --draft \
  --title "feat: <short description> (closes #<NUMBER>)" \
  --body "Closes #<NUMBER>

Work in progress." \
  --base master --assignee @me
```

Then make the directory this cycle's proof of work lives in — `<NNNN>` is the
zero-padded issue number, `<slug>` a short kebab-case description:

```bash
mkdir -p docs/proof_of_work/<NNNN>-<slug>
```

Four kinds of file end up there: `findings-coder.md`, `findings-review.md`,
`code-decision-<x>.md` and `review-<x>.md`, where `<x>` is the round of the
inner loop. They are written by the subagents that do the work and committed
on the branch like any other change. See
[proof_of_work/README.md](proof_of_work/README.md).

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
#
#  Write two files under docs/proof_of_work/<NNNN>-<slug>/:
#  - code-decision-<x>.md (x = this round): the approach you took, what you
#    rejected and why, and anything you were unsure about
#  - findings-coder.md (append if it exists): what you found along the way —
#    obstacles, surprises, and any bugs or weak spots you noticed, INCLUDING
#    ones outside this issue's scope, each with file/line and a suggested fix
#
#  Commit and push everything, the two files included."
```

After the subagent reports, commit and push if it did not do so already:

```bash
# Ensure everything is committed and pushed
git add -A
git commit -m "feat(core): implement <short description> (closes #<NUMBER>)"
git push origin feat/issue-<NUMBER>-<description>
```

**Commit message convention:**
- Type: `feat`, `fix`, `docs`, `refactor`, `ci`, `test`, `chore`
- Scope: `(core)`, `(runtime)`, `(command)`, `(config)`, `(dto)` etc.
- Reference to issue: `(closes #<NUMBER>)`

> **Coder output contract (non-negotiable):** the subagent must always report
> (1) changed files, (2) the biggest problem it faced with details, and
> (3) any discovered bugs / places to improve — even ones outside the current
> issue's scope — and must write (2) and (3) into `findings-coder.md` rather
> than leaving them in chat. The main session reuses them for the final report
> (step 14).

---

## 4. Code Review via Subagent

After implementation, run a code review using a subagent (separate agent with
its own context). The subagent checks:

- Alignment with project structure (PSR-4, Symfony Bundle conventions)
- Type correctness and signatures (PHPStan level 8)
- Error handling and edge cases
- Coding style (PSR-12, php-cs-fixer)
- Test coverage
- Security (Workerman child processes, HTTP input, process supervision)

**Review round `<x>` reads `findings-review.md` first.** Before looking for
anything new it goes through what earlier rounds recorded and says, for each,
whether it is still present. Nothing is deleted from that file — a finding the
coder believes fixed and the review still sees is a disagreement worth keeping
on the record.

```bash
# The subagent receives a task like:
# "Code review the changes in files: <list of files>.
#  Read docs/helpers/ first, via the TAG INDEX (index + only the entries whose
#  tags match the files in the diff), and flag any violations of documented
#  decisions by entry id. You do NOT write to docs/helpers/ — propose
#  candidate entries in your report.
#  Then read docs/proof_of_work/<NNNN>-<slug>/findings-review.md and, for
#  every finding an earlier round left open, state explicitly: still present /
#  fixed / not a real finding (with evidence). Only then look for NEW issues.
#  Check: type correctness, error handling, PSR-12 compliance,
#  missing tests, outdated documentation.
#
#  Write two files under docs/proof_of_work/<NNNN>-<slug>/:
#  - review-<x>.md (x = this round): your full review for this round
#  - findings-review.md (append if it exists): one entry per finding —
#    file:line, what is wrong, severity, and what happened to it
#
#  For any finding an automated check could plausibly have caught, say which
#  check that would be. If the same class of defect has been seen before,
#  write the check in this PR rather than reporting it again."
```

Severities are `high`, `medium`, `low`, `nit`.

Anything non-obvious the review learned is reported as a **candidate**
knowledge-base entry (title, tags, trigger, one paragraph). The review does not
write to `docs/helpers/` — only the retro step does, see
"Knowledge Base (docs/helpers/)" below.

> **Why a subagent:** code review reads the full diff plus surrounding code,
> runs static analysis, and produces a structured findings list. Delegating
> keeps the main session focused on fixes and the next workflow step.

---

## 5. Fix the Findings

```bash
# For each finding:
# 1. Apply the fix
# 2. Note in findings-review.md what happened to it
# 3. Commit
git add -A
git commit -m "fix: <description of fix>"
git push origin feat/issue-<NUMBER>-<description>
```

**All findings get an answer — even the `nit`s.** Fixed, deliberately not
fixed (say why, and cite `docs/helpers/decisions.md` if there is an entry), or
not a real finding (say what the evidence was). Silence is not an answer.

> **A finding first seen in round 2 or later escaped round 1.** That usually
> means a check was missing rather than that a reviewer was unlucky — prefer
> adding the test over just fixing the line.

---

## 6. Repeat the Review

After fixing, invoke the review subagent again for round `<x>+1`. It writes
`review-<x+1>.md` and appends to `findings-review.md`.

Repeat steps 5 → 6 until the review reports no open findings.

Four rounds is a lot. A loop that has not converged by then usually needs a
decision rather than another iteration — narrow the issue and file the rest
separately, throw the approach away and re-plan, or ask the user. Say which
one you chose, in the last `code-decision-<x>.md`.

> **Never lower a gate to reach a clean round.** Dropping the coverage floor
> or disabling a linter rule to make a round look clean is forbidden outright
> (see `docs/helpers/decisions.md`).

## 7. Run Linters and Tests Locally

Before opening a PR, verify that all linters and tests pass on your machine:

```bash
# Run all linters (php-cs-fixer dry-run, phpstan, rector dry-run) and the
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

\`docs/proof_of_work/<NNNN>-<slug>/\` — <N> review round(s)

## Code Review

- [ ] Passed subagent code review
- [ ] Every finding answered"

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

CI workflow (`.github/workflows/tests.yaml`) has four jobs:

1. **lint** – `composer validate --strict`, `composer audit`, then
   `composer lint` (php-cs-fixer, phpstan, rector dry-run and
   `bin/kb-lint.php`)
2. **tests matrix** (PHP 8.2–8.5 × Symfony 6.4–8.0) – unit + E2E tests;
   `needs: lint`. The PHP 8.2 / Symfony 6.4 leg also enforces the
   line-coverage floor
3. **benchmark** – advisory; CI runner timing varies too much to gate merges
   on it
4. **ci** – aggregator: fails unless `lint` and `tests` succeeded

A run superseded by a newer push on the same pull request is cancelled
(`concurrency: cancel-in-progress`), so two full matrices never grind against
the same PR at once.

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

> **Note:** The pre-push hook runs `composer lint` before every push. To skip
> it in emergencies: `git push --no-verify` — CI will still say no, that is
> the point.

**Repeat until all CI checks pass.**

> **A CI failure is an escaped defect.** Record it in `findings-review.md`
> like any other finding before fixing it — round 1 should have caught it and
> did not, which is usually a missing check rather than bad luck.

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

## 14. Report Implementation Problems and Offer a GitHub Issue

At the end of the workflow, present the findings collected during the cycle and
decide with the user whether they deserve a dedicated GitHub issue. They are
already written down — read them out of
`docs/proof_of_work/<NNNN>-<slug>/findings-coder.md` and `findings-review.md`
rather than out of the chat log, which may since have been compacted.

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

Finally, fold in any knowledge-base candidates the coder and the review
proposed: this step is the single writer for `docs/helpers/` (see "Knowledge
Base" below). Prefer writing the check over writing the entry — if a
regression test, PHPStan rule or lint rule could catch the class of defect,
add it instead.

---

## Proof of Work (docs/proof_of_work/)

Every cycle leaves four kinds of file behind, in `docs/proof_of_work/<NNNN>-<slug>/`:

| File | Written by | What goes in it |
| --- | --- | --- |
| `findings-coder.md` | the coder, appended across rounds | obstacles, surprises, bugs noticed in passing — including ones outside this issue's scope |
| `findings-review.md` | the review, appended across rounds | one entry per finding: `file:line`, what is wrong, severity, what happened to it |
| `code-decision-<x>.md` | the coder, one per round | the approach taken in round `<x>`, what was rejected, what was uncertain |
| `review-<x>.md` | the review, one per round | the review output for round `<x>` |

`<x>` is the round of the inner loop, starting at 1. Six files means three
rounds, and three rounds means something was hard — which is most of what a
reader wants to know at a glance.

The two `findings-*` files are separate because the two roles disagree, and a
shared file turns disagreement into an edit war. Keeping them apart lets the
review say "still present" about something the coder called fixed, with both
statements surviving in the record.

Nothing validates these files. There is no schema, no manifest and no CI gate
— a reader checks them during review, the same way they check the code.
[proof_of_work/README.md](proof_of_work/README.md) explains why the machinery
that used to do it was removed.
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

**One writer.** Only the **main session**, at the end of the cycle (step 14),
writes to `docs/helpers/`. `coder`/`coder-high` and `review`/`review-critical`
**propose** candidate entries in their report — title, tags, trigger, one
paragraph — and the main session decides what lands. Two writers produced
duplicates, unlabelled entries and a file that had to be read in full (issue
#686, `DEC-009`); a subagent that appends to the knowledge base itself is
doing the wrong thing.

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

Which agent runs at which step. These are **role names, not a harness**: the
workflow assumes you can start a subagent with its own context and give it a
scoped instruction, and assumes nothing else. Whatever tool provides that —
and wherever it keeps its prompts — is a local choice.

| Step | Agent | Role |
| --- | --- | --- |
| 1 | `delegate` | triage open issues, return a ranked shortlist |
| 1b | `scout` | fast recon: relevant files, flows, KB tags to load |
| 1c | `context-builder` | compress the issue + code into a handoff brief |
| 2b | `planner` | plan the change before any edit |
| 2c | `oracle` | judgement call on approach when the plan is contested |
| 3 | `coder` / `coder-high` / `worker` | implement; write `code-decision-<x>.md` and `findings-coder.md` |
| 4, 6 | `review` / `review-critical` | code review; write `review-<x>.md` and `findings-review.md` |
| 11 | `delegate` | compress CI logs into actionable failures |
| 14 | `reviewer` | verify candidate findings before opening GitHub issues |

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
#    alternative: delegate full triage to a subagent ("List top 5 impactful…")

# 2. Feature branch — name derived by the helper (type from labels/prefix)
branch="$(bin/gh-branch <NUMBER>)"
#    workflow/tooling changes: bin/gh-branch <NUMBER> process

# 2.5 Draft PR first, then the proof-of-work directory
git push -u origin "$branch"
gh pr create --draft --title "…(closes #<NUMBER>)" --body "Closes #<NUMBER>" --base master --assignee @me
mkdir -p docs/proof_of_work/<NNNN>-<slug>

# 3. Implementation (worker/coder subagent)
#    subagent: "Implement issue #<NUMBER>…"
#    writes code-decision-1.md + findings-coder.md, commits them with the change
#    report must include: files changed, BIGGEST problem, discovered bugs
#    / places to improve (also out of scope)

# 4. Code review (subagent) — reads findings-review.md first, then looks for new issues
#    writes review-1.md + findings-review.md

# 5-6. Fix, answer every finding, re-review (review-2.md, review-3.md, …)
#    a finding that an automated check could have caught: write the check
#    past ~4 rounds, decide instead of iterating — narrow, re-plan, or ask

# 7. Run linters and tests locally
composer lint && composer test

# 8. Update CHANGELOG.md

# 9. Fill in the PR body and take it out of draft
gh pr edit --title "…" --body "…" && gh pr ready

# 10-11. CI
gh pr checks --watch
# ... if failures → fix, review, push → wait for CI (repeat)

# 12. Merge
gh pr merge --squash --delete-branch

# 13. Switch back to master
git checkout master && git pull origin master

# 14. Report + offer GitHub issue for discovered problems
#    show: biggest problem(s), discovered bugs / places to improve
#    (read them out of findings-coder.md and findings-review.md)
#    verify each candidate with a review subagent (finding is real?
#    no duplicate on GitHub? use --limit >30 in issue lists)
#    then ask: "Create GitHub issue(s)?" → if yes: gh issue create ...
#    fold any accepted knowledge-base candidates into docs/helpers/
```

---

## Subagent Usage Summary

Most steps of this workflow are delegated to subagents to keep the main
session's context lean.

| Step | Subagent task                              | Why delegate                          |
| ---- | ------------------------------------------ | ------------------------------------- |
| 1    | Triage open issues, return ranked shortlist | Issue bodies + comments are token-heavy |
| 3    | Implement the issue (worker/coder)         | Coding context is token-heavy; agent returns structured report (files, biggest problem, discovered bugs) |
| 4, 6 | Code review of the implementation diff, `findings-review.md` first | Full diff + surrounding code is token-heavy; the review must revisit every open finding before hunting for new ones |
| 14   | Verify candidate findings before creating GitHub issues (read-only: is the finding real? is it already tracked?) | GitHub duplicate search (open + closed, `--limit` > 30) plus code verification across several findings is query-heavy |

All subagents have read/write/edit/bash tools and operate on the same
repository (the step-14 verifier is instructed to run read-only). Give each one
a clear, scoped instruction and a defined output format (ranked list with
rationale / numbered findings list with `file:line | description | severity` /
coder report with biggest problem + discovered bugs).

The coder and the review each write their own files under
`docs/proof_of_work/<NNNN>-<slug>/` — the main session does not retype their
output into a summary. A report that only exists in chat is gone the moment
the context is compacted.

**Knowledge base:** implementation and review subagents read `docs/helpers/`
before starting — the tag index plus the entries matching the files in the diff
— and **propose** candidate entries in their report. They never append; the
main session folds accepted candidates in at step 14 (see "Knowledge Base
(docs/helpers/)" above).

---

## Notes

- **gh** must be configured and authenticated (`gh auth status`).
- `master` carries **no GitHub branch protection** — this is a solo-maintainer
  project with a single collaborator, and GitHub does not allow approving your
  own pull request, so there is no reviewer to require one from. What actually
  gates a merge: CI (the `ci` aggregator job) plus the maintainer's own
  decision to merge.
- Pre-push hook automatically runs `composer lint` before each push. To skip:
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
  nothing. Ask the user instead.
- `docs/proof_of_work/` carries `export-ignore`, so it is not part of the
  distributed package.
