# code-decision-1.md — removing the draft-PR-first step (workflow 2.5)

Round 1. Scope: docs-only change for issue #704, touching exactly three
files — `docs/workflow.md`, `docs/process-notices.md` (N-12),
`docs/process-changelog.md` (new entry #5) — plus this directory.

## The approach

**Step 2.5 was deleted, not renumbered.** The task offered two placements
for the proof-of-work directory creation (fold into the end of step 2, or a
standalone note right after it). I folded it into the end of step 2, after
the "Existing examples" list, keeping the exact `mkdir -p` block and the
four-kinds-of-file paragraph from the old step 2.5 verbatim. Rationale: a
standalone unnumbered heading between 2 and 3 would have read like a
leftover numbered step; step 2 is the natural home for "set up the branch
and this cycle's directory".

**Step 9 was rewritten in place, keeping its number.** The task said "merge
steps 2.5 and 9" and "step 9 must become the single place where the PR is
created". Renumbering the later steps (10-14 -> 11-15, plus the Quick
Reference and the Agent Map's step references) was rejected: nothing
outside the file links to the step headings (verified with a repo-wide
grep; only `#release-gate` is linked from release-workflow.md), but
renumbering would churn the diff massively and add zero behavioural value.
Step 9 keeps its slot, now titled "Open the Pull Request" with a `gh pr
create` block (no `--draft`, no `gh pr ready`), and step 10's "Wait for CI"
flows straight from it.

**Every stale claim was swept, not just the obvious ones.** The H1 lost
"Draft PR". Step 6's bash comment ("before moving on to linting or the PR
being marked ready") and step 7's bold line ("Only mark the PR ready for
review when all lints and tests pass locally") both assumed the PR existed
from 2.5; step 7 now says "Only open the pull request (step 9) when all
lints and tests pass locally". Post-edit `grep -n "2\.5\|draft\|--draft\|gh
pr ready\|gh pr edit\|empty branch" docs/workflow.md` returns nothing.

**Step 9's prose preserves the surviving arguments.** The issue said the
two justifications die but `closingIssuesReferences` and PR-as-convergence-
point survive. The new step 9 says both: the `Closes #<NUMBER>` body line
links the issue from the first push, and the PR is where diff + CI status +
review conversation converge — it is just created when there is content to
converge.

**N-12 was rewritten as superseded, with the historical rationale kept
intact.** `tests/Process/ProcessDocsTest.php` requires every N-## section
to still contain a `**Trigger:**` line, so the entry states the trigger has
effectively fired instead of deleting it — the trigger marker stays, and
the new facts (#697 files-as-POW, #670 wasted matrix run, `gh pr create
--draft` GraphQL failure) are given as prose under the "Rejected because"
heading. N-13 was not touched, per scope.

**Changelog entry #5 follows the file's own Format and the #1-#4 shape**:
Date / Issue / PR (marked TBD since no PR number exists yet) / What / Why /
Success criterion / Outcome: pending. The success criterion is a pure grep:
no `2.5`, `--draft` or `gh pr ready` in workflow.md, and the only
`gh pr create` code block in step 9.

## What I rejected

- **Renumbering steps 10-14** to absorb 2.5's slot (rejected: churn without
  value; nothing links to step headings; the task itself kept "step 9").
- **A standalone note heading after step 2** for the mkdir block (rejected:
  a numbered-less heading between numbered steps reads as a leftover; the
  end of step 2 is where a reader looks for "setup" actions).
- **Keeping `gh pr ready`** in any form (rejected: the PR is created ready;
  the review rounds happen on the branch before the PR exists, so nothing
  toggles readiness afterwards).
- **Leaving N-12 as a live rejection with a "trigger has fired" note** that
  preserved the old body and appended a footnote (rejected: the task
  explicitly wanted the stale rationale rewritten so the entry is "no
  longer a live rejection"; a footnote would leave the stale reasoning
  reading as current).
- **Quoting the literal failing command `gh pr create --draft` in
  workflow.md's step 9** (rejected: the changelog's success criterion greps
  for `--draft` in workflow.md; the prose refers to "a PR opened on a
  branch with no new commits" and the GraphQL error text instead, and the
  full literal command lives in N-12 where nothing greps for it).

## Things I was unsure about

- **Whether to keep `git push -u origin "$branch"` in the Quick Reference
  at branch-creation time.** Pushing before any commits exist is harmless
  (the branch carries master's HEAD; `gh pr create` fails on "no commits
  between", plain `git push` does not) and the task explicitly said to
  keep it next to the mkdir line, so it stayed. It is not in the step 2
  body — the first push there remains step 3's "commit and push".
- **The H1 arrow order.** "PR" now sits after "Review Rounds" and before
  "CI" (matching actual step order 9 -> 10), which is arguably more
  accurate than the old order, where "Draft PR" was second. The H1 was
  always a coarse summary, so no deeper rationale was needed.
- **The exact grep set for the success criterion.** `gh pr edit` is also
  gone but not grepped; the criterion names the three strings that would
  indicate a partial revert (`2.5`, `--draft`, `gh pr ready`). Good enough
  to catch the described regression without over-specifying.
