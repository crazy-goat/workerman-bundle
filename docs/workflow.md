# Workflow: Issue → Feature Branch → Implementation → Code Review → PR → CI → Merge

This document describes the complete workflow for handling issues in the
[crazy-goat/workerman-bundle](https://github.com/crazy-goat/workerman-bundle)
repository using `gh` and `git`.

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
bin/gh-branch <NUMBER> feat        # force type (fix|feat|docs|perf|refactor|chore|test|build|ci)
bin/gh-branch <NUMBER> --push      # also push with upstream
branch=$(bin/gh-branch <NUMBER>)   # capture the name (printed to stdout) for later steps
```

The `fix`/`feat`/… type is inferred from a `[Type]` title prefix
(`[Bug]`→`fix`, `[Feat]`→`feat`, `[Tests]`→`test`, …), falling back to issue
labels (`bug`/`security`→`fix`, `enhancement`→`feat`, `documentation`→`docs`,
…), and finally to `fix`. The branch is created from the
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

## 4. Code Review via Subagent

After implementation, run a code review using a subagent (separate agent with
its own context). The subagent checks:

- Alignment with project structure (PSR-4, Symfony Bundle conventions)
- Type correctness and signatures (PHPStan level 6)
- Error handling and edge cases
- Coding style (PSR-12, php-cs-fixer)
- Test coverage
- Security (Workerman child processes, HTTP input, process supervision)

```bash
# The subagent receives a task like:
# "Code review the changes in files: <list of files>.
#  Read docs/helpers/ (faq.md, decisions.md) first and flag any
#  violations of documented decisions.
#  Check: type correctness, error handling, PSR-12 compliance,
#  missing tests, outdated documentation.
#  List all issues to fix."

After the review, the subagent should append any non-obvious findings to
`docs/helpers/` (see "Knowledge Base (docs/helpers/)" below) — typically
as part of the fix commits that follow.
```

> **Why a subagent:** code review reads the full diff plus surrounding code,
> runs static analysis, and produces a structured findings list. Delegating
> keeps the main session focused on fixes and the next workflow step.

---

## 5. Fix Issues Found in Code Review

```bash
# For each problem found:
# 1. Apply the fix
# 2. Commit with a descriptive message
git add -A
git commit -m "fix: <description of fix>"
git push origin feat/issue-<NUMBER>-<description>
```

**All issues must be fixed – even the least significant ones.**

---

## 6. Repeat Code Review

After fixing, invoke the subagent for another code review.

Repeat steps 5→6 until the subagent reports no issues.

> **Acceptance criteria:** The subagent responds: "Code looks good, no issues
> to fix."

---

## 7. Run Linters and Tests Locally

Before opening a PR, verify that all linters and tests pass on your machine:

```bash
# Run all linters (php-cs-fixer dry-run, phpstan, rector dry-run)
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

**Only create the PR when all lints and tests pass locally.**

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

## 9. Create a Pull Request

```bash
# Create a PR from the feature branch to master
gh pr create \
  --title "feat: <short description> (closes #<NUMBER>)" \
  --body "## Description

Closes #<NUMBER>

## Changes

- <list of changes>

## Changelog

<!-- Describe the changelog entry for this PR -->

## Code Review

- [ ] Passed subagent code review
- [ ] All review comments addressed" \
  --base master \
  --assignee @me
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
1. **lint** – composer validate, composer audit, php-cs-fixer, phpstan, rector
2. **tests matrix** (PHP 8.2–8.5 × Symfony 6.4–8.0) – unit + E2E tests
3. **ci** – aggregator checking that lint and tests passed

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

> **Note:** The pre-push hook runs `composer lint` before every push.
> To skip the hook in emergencies: `git push --no-verify`

**Repeat until all CI checks pass.**

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
branch=$(bin/gh-branch <NUMBER>)  # capture name for the subagent task below

# 3. Implementation (worker/coder subagent)
#    subagent: "Implement issue #<NUMBER>..."
#    report must include: files changed, BIGGEST problem, discovered bugs
#    / places to improve (also out of scope)
git add -A && git commit -m "feat: implement <desc> (closes #<NUMBER>)"
git push origin feat/issue-<NUMBER>-<description>

# 4. Code Review (subagent)
# ... fix issues ... (repeat until clean)

# 5. Run linters and tests locally
composer lint
composer test

# 6. Update CHANGELOG.md

# 7. PR
gh pr create --title "feat: <desc> (closes #<NUMBER>)" --body "..." --base master

# 8. CI
gh pr checks --watch
# ... if failures → fix, code review, push → wait for CI (repeat)

# 9. Merge
gh pr merge --squash --delete-branch
gh issue close <NUMBER>

# 10. Switch back to master
git checkout master && git pull origin master

# 11. Report + offer GitHub issue for discovered problems
#    show: biggest problem(s), discovered bugs / places to improve
#    verify each candidate with a review subagent (finding is real?
#    no duplicate on GitHub? use --limit >30 in issue lists)
#    then ask: "Create GitHub issue(s)?" → if yes: gh issue create ...
```

---

## Subagent Usage Summary

Four steps of this workflow are delegated to subagents to keep the main
session's context lean:

| Step | Subagent task                              | Why delegate                          |
| ---- | ------------------------------------------ | ------------------------------------- |
| 1    | Triage open issues, return ranked shortlist | Issue bodies + comments are token-heavy |
| 3    | Implement the issue (worker/coder)         | Coding context is token-heavy; agent returns structured report (files, biggest problem, discovered bugs) |
| 4    | Code review of the implementation diff     | Full diff + surrounding code is token-heavy |
| 14   | Verify candidate findings before creating GitHub issues (read-only: is the finding real? is it already tracked?) | GitHub duplicate search (open + closed, `--limit` > 30) plus code verification across several findings is query-heavy |

All subagents have read/write/edit/bash tools and operate on the same
repository (the step-14 verifier is instructed to run read-only). Give each
one a clear, scoped instruction and a defined output format (ranked list
with rationale / numbered findings list / coder report with biggest problem
+ discovered bugs / per-finding verification verdict).

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
- Pre-push hook automatically runs `composer lint` before each push.
  To skip: `git push --no-verify`
- Keep feature branches short-lived. If a rebase is needed:
  ```bash
  git fetch origin master
  git rebase origin/master
  git push --force-with-lease origin feat/issue-<NUMBER>-<description>
  ```
- Code review via subagent runs locally – the subagent has access to
  read/write/edit/bash tools. Give it clear instructions on what to check.
