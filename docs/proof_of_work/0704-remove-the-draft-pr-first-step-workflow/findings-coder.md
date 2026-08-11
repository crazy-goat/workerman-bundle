# findings-coder.md — issue #704 (remove the draft-PR-first step)

Obstacles, surprises and weak spots noticed while implementing the
docs-only change. All files and lines refer to the post-edit working tree
unless stated otherwise.

## Obstacles

1. `tests/Process/ProcessDocsTest.php` constrains the notices file more
   tightly than the task brief suggested. Its
   `testProcessNoticesContainsEveryNoticeWithATrigger` requires every
   N-01..N-13 section to contain a literal `**Trigger:**` marker, so N-12
   could not simply drop or blank its trigger. Solution: keep the heading,
   keep the phase-1 rationale verbatim, and state in prose under "Rejected
   because" that the rejection is superseded while the trigger section says
   it has effectively fired. The alternative — deleting the Trigger line —
   would break the test.
   File: `tests/Process/ProcessDocsTest.php:118` (`testProcessNoticesContainsEveryNoticeWithATrigger`).

2. The proof-of-work four-kinds-of-file paragraph had to survive in
   `docs/workflow.md` because `testWorkflowDocumentsTheFourProofOfWorkFiles`
   asserts all four file names (`findings-coder.md`, `findings-review.md`,
   `code-decision-`, `review-`) appear in the file. Moving the paragraph
   from step 2.5 into the end of step 2 preserved that. The QR's old
   step-2.5 block also carried `docs/proof_of_work/<NNNN>-<slug>`; the new
   QR keeps the mkdir line under step 2.

3. MarkdownLinkTest resolves links case-sensitively and strips fenced
   blocks — the only same-file anchor in workflow.md is
   `#proof-of-work-docsproof_of_work` (intro paragraph, targeting the
   `## Proof of Work (docs/proof_of_work/)` heading), which was not
   renamed. No other tracked doc links to workflow step headings
   (verified: only `workflow.md#release-gate` links exist, from
   `docs/release-workflow.md`), so the step 9 heading rename was safe.

## Surprises

1. The task brief said "the step 3 bash comment block, step 11/12/14
   prose, the Notes section, the Subagent Usage Summary table" needed
   sweeping for stale PR/draft assumptions — but grep found none there.
   Only the H1, step 2.5, step 6's commit comment, step 7's bold line,
   step 9 and the QR were affected. The brief was written defensively; the
   actual sweep surface was smaller than advertised.

2. The pre-push hook (composer lint) runs on the plain `git push -u origin
   "$branch"` the Quick Reference keeps at branch-creation time — so the
   early push costs a lint run on master state. Same as before this change
   (the old step 2.5 pushed at the same point), so not a regression.

## Weak spots noticed (in scope)

1. `docs/workflow.md` step 9 prose includes the GraphQL error text "No
   commits between master and <branch>" in plain prose. The literal
   `<branch>` will be rendered as an HTML tag by GitHub's Markdown in some
   renderers, but it is inside a sentence surrounded by quotes and matches
   the issue's wording; the same pattern already exists elsewhere in the
   repo docs, so it was kept. If it ever renders wrong, wrap it in
   backticks.
2. The changelog entry #5's "PR: TBD" will need filling in once the PR
   exists — the entry's own convention (#4 uses "(this PR)") was not
   followed because the task explicitly said to leave it out or mark TBD.

## Weak spots noticed (out of scope)

1. `docs/process-notices.md` header blockquote says "N-01 to N-13 are
   history" and that their "triggers mostly refer to tooling that no
   longer exists and cannot fire". After this change N-12's trigger is
   different: it *has* fired (and says so). The blockquote's blanket
   phrasing is now slightly inaccurate — it says the notices are kept
   "because the reasoning is still worth reading", which still holds for
   N-12, but a reader skimming the header could conclude N-12's trigger is
   dead when it is explicitly marked fired. Suggested fix: reword the
   blockquote to "N-01..N-11 and N-13" or add "N-12's trigger has fired
   and is marked as such". The blockquote currently matches
   `tests/Process/ProcessDocsTest.php`'s regex (`history`/`no longer
   exist`/`cannot fire`/`removed`), so the reword must keep one of those
   words.
   File: `docs/process-notices.md:7-12`.
2. The same header calls the file "Rejected Alternatives" while N-12 now
   explicitly says its rejection "is no longer live policy". Not
   contradictory (the reasoning is still worth reading), but the file's
   one-line self-description at line 3 ("A registry of process
   alternatives that were considered and rejected") could mention that
   some entries are superseded with fired triggers. Cosmetic.
   File: `docs/process-notices.md:3`.
3. `tests/Process/ProcessDocsTest.php:107`
   (`testProcessNoticesSaysItsTriggersReferToRemovedTooling`) asserts the
   header matches `/history|no longer exist|cannot fire|removed/i`. This
   is a soft, regex-on-prose check: any future header rewrite for accuracy
   (see finding 1 above) must keep one of those four words or the test
   fails for a reason unrelated to its intent. Suggested fix: assert on a
   stable anchor (e.g. the "## N-01" heading position or a specific known
   sentence) instead of a keyword regex.
4. The Quick Reference and the step 2/9 prose now disagree slightly on the
   first push: the QR pushes (`git push -u`) right after branch creation,
   step 3's block pushes after implementation, step 9's prose says the PR
   "is linked from the first push". Harmless duplication, pre-existing
   style of the file, but a future reader may wonder which push is
   canonical. Not changed: the QR is a condensed reference and the task
   explicitly required keeping its push line.
