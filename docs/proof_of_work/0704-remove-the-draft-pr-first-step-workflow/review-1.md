# review-1.md — issue #704 (remove the draft-PR-first step)

Round 1 critical review of the diff on branch
`process/issue-704-remove-the-draft-pr-first-step-workflow`
(`git diff origin/master..HEAD`).

## Earlier findings

This is round 1. `findings-review.md` does not exist yet — nothing to revisit.
Straight to hunting.

## Overall verdict

**Clean enough to merge.** The change is a docs-only process edit that does
exactly what issue #704 asks: it deletes workflow step 2.5 (draft PR before
code), moves PR creation into step 9 (created ready, no `--draft`, no
`gh pr ready`), folds the proof-of-work `mkdir` block into the end of step 2,
and rewrites N-12 as a superseded rejection with a fired trigger. The
success-criterion grep (`grep -nE "2\.5|--draft|gh pr ready" docs/workflow.md`)
returns nothing. The three relevant test classes
(`MarkdownLinkTest`, `ChangelogStructureTest`, `ProcessDocsTest`) all pass
(115 tests, 592 assertions). Fences are balanced in every changed file. No
internal markdown link broke.

No high or medium findings. Three low findings and a few residual
observations, all documented below. None block the merge; they are accuracy
and cross-repo-consistency nits in permanent process prose.

## Verification performed

- **Success criterion:** `grep -nE "2\.5|--draft|gh pr ready" docs/workflow.md`
  → empty (exit 1). The only multi-line `gh pr create` code block is in
  step 9 (line 376); the Quick Reference keeps its one-line echo (line 714);
  the prose mention at line 369 is not a code block. Criterion satisfied.
- **Repo-wide stale sweep:** `grep -rnE "gh pr edit|gh pr ready|--draft"`
  across tracked `.md`/`.php`/`.sh` (excluding `proof_of_work/0704`, vendor,
  `.pi-subagents`). Surviving hits are all intentional: `docs/process-changelog.md:198`
  and `docs/process-notices.md:289` quote the *failing* `gh pr create --draft`
  command as historical evidence (not an instruction);
  `docs/release-workflow.md` uses `--draft=false` for GitHub *release*
  drafts (a different concept, out of scope). `bin/`, `docs/helpers/`,
  `CONTRIBUTING.md` are clean.
- **Tests:** `php -d phar.readonly=0 vendor/bin/phpunit --no-coverage
  --filter 'MarkdownLinkTest|ChangelogStructureTest|ProcessDocsTest'` →
  OK (115 tests, 592 assertions). `MarkdownLinkTest` resolves the
  same-file intro anchor `#proof-of-work-docsproof_of_work` and checks
  fence balance across all tracked `.md` (data-provider-driven).
  `ProcessDocsTest::testProcessNoticesContainsEveryNoticeWithATrigger`
  still finds `**Trigger:**` in every N-01..N-13 section (N-12 keeps it,
  now stating it has fired). `ProcessDocsTest::testWorkflowDocumentsTheFourProofOfWorkFiles`
  still finds all four POW file names in `docs/workflow.md` (the
  four-kinds paragraph moved intact to the end of step 2).
- **#670 structural facts:** `.github/workflows/tests.yaml` has
  `concurrency: cancel-in-progress: true` (lines 9-11) and a matrix
  spanning PHP 8.2–8.5 × Symfony 6.4–8.0 (lines 47-67). The "full matrix"
  range claim is accurate.
- **#697 cross-reference:** commit `c3facfc` = "process: replace the
  machine-checked proof of work with four Markdown files (#686) (#697)".
  The claim "since PR #697 the proof of work is four Markdown files under
  `docs/proof_of_work/`" is accurate.
- **Internal consistency of `docs/workflow.md`:** H1 reads
  "Issue → Feature Branch → Implementation → Review Rounds → PR → CI → Merge"
  (PR after Review Rounds, matching step order 9→10; "Draft" dropped).
  Step 7's bold line says "Only open the pull request (step 9) when all
  lints and tests pass locally." Step 6's commit comment says "before
  moving on to linting" (no longer mentions "the PR being marked ready").
  Steps 3-6 prose contains no PR-existence assumption (verified by grep).
  The Quick Reference step 9 line matches the step 9 code block
  (`gh pr create --title … --body … --base master --assignee @me`). No
  orphaned `gh pr edit` / `gh pr ready` / `--draft` references.
- **`closingIssuesReferences` claim:** GitHub populates this GraphQL field
  from auto-close keywords (`Closes`/`Fixes`/`Resolves #N`) in the PR body
  (and commit messages). The claim that it "comes from the `Closes #<NUMBER>`
  line in the body, not from when the PR was opened" is accurate.
- **GraphQL error text:** `gh pr create` on a branch with no commits ahead
  of the base fails with "No commits between master and `<branch>`"
  (GitHub refuses head-not-ahead-of-base PRs). Matches the task brief and
  known GitHub behaviour; not live-tested.

## New findings

### F-1 | docs/process-notices.md:14 | header blockquote says triggers "cannot fire" but N-12's trigger has fired | low

**Evidence:** The header blockquote (lines 7-14) states the N-01..N-13
triggers "mostly refer to tooling that no longer exists and cannot fire"
and that the notices are kept "not because they are live policy." After this
change, N-12's `**Trigger:**` (line 297) explicitly says "this trigger has
effectively fired" and the entry is marked superseded. So one of the 13
triggers has fired, contradicting the header's blanket "cannot fire" framing.

**Impact:** Low. The word "mostly" is a hedge — it does not claim *all*
triggers cannot fire, so the statement is technically defensible, and N-12
itself is clearly marked. But the header is the first thing a reader sees;
a skimmer could conclude N-12's trigger is dead and skip checking it. The
file's own Format section (lines 16-18) tells readers a trigger "can be
re-litigated once it has," which mitigates the risk. The coder self-reported
this in `findings-coder.md` (weak spot out-of-scope #1) and noted that any
reword must keep one of `history`/`no longer exist`/`cannot fire`/`removed`
to satisfy `ProcessDocsTest::testProcessNoticesSaysItsTriggersReferToRemovedTooling`.

**Smallest safe fix direction:** Scope the "cannot fire" claim to
"N-01..N-11 and N-13" or append "— N-12's trigger has fired and is marked
as such" to the blockquote, keeping one of the four keywords the test
greps for.

**Automated check that would catch it:** None today. The existing
`testProcessNoticesSaysItsTriggersReferToRemovedTooling` only asserts a
keyword is *present* in the header; it would not detect a header that
contradicts a fired trigger. A test asserting "no notice whose `**Trigger:**`
says it fired is covered by a header claim that triggers cannot fire" would
be over-specified for the value it adds.

### F-2 | .github/workflows/tests.yaml:6 and tests/GithubWorkflowsTest.php:56 | "Marking a draft ready" motivation references the draft→ready pattern this issue removes | low

**Evidence:** `tests.yaml:6` comment: "Marking a draft ready, or pushing
again while a run is still going, used to leave two full matrices grinding
on the same pull request." `GithubWorkflowsTest.php:56` docblock (for
`testASupersededRunIsCancelledInsteadOfFinishing`): "Marking a draft ready
and pushing a moment later once left two full matrices — eighteen legs —
running against the same pull request." Both cite the draft→ready
transition as half the motivation for the `concurrency: cancel-in-progress`
guard — the exact pattern this issue removes from the repo's workflow.

**Impact:** Low. Both are past-tense historical comments ("used to leave",
"once left"), not live instructions, and the concurrency guard still
functions correctly. The *other* half of the motivation ("pushing again
while a run is still going") is still live and is in fact the #670 scenario
the changelog entry relies on (seed-commit run cancelled by the
implementation push). The guard and its test are unaffected. The only
inconsistency is that the comments describe a workflow transition
(draft→ready) that this repo no longer uses.

**Smallest safe fix direction:** Drop "Marking a draft ready, or" from the
`tests.yaml` comment and "Marking a draft ready and" from the test docblock,
leaving the "pushing again" justification, which is the one the #670
account actually depends on.

**Automated check that would catch it:** A grep for "draft" in
`.github/workflows` and `tests/` could surface it, but no such test exists
and it would also flag `release.yaml`'s legitimate `draft: true` (GitHub
release drafts, a different concept).

### F-3 | docs/workflow.md:368-369, docs/process-notices.md:285-287, docs/process-changelog.md:195-197 | "3 minutes later" #670 timing is uncorroborated by any committed artifact | low

**Evidence:** All three files state that in the #670 cycle "the
implementation push [3] minutes later cancelled it." I checked the
committed record for corroboration: `docs/proof_of_work/0670-sfxdownloader-zip-extraction-cleanup/`
(four files) is silent on the cancel/seed/timing story — it records the
*SfxDownloader zip-extraction* code fix, not a process observation. The
pre-existing `docs/process-changelog.md` had no #670 entry before this PR.
The only source for the "3 minutes" figure is the task brief; the coder
repeated it verbatim into three permanent docs without independent
verification (the coder's `findings-coder.md` does not flag uncertainty
about the duration).

**Impact:** Low. The *structural* claim — a draft PR's seed-commit CI run is
wasted because `concurrency: cancel-in-progress` cancels it when the
implementation push arrives — is verifiable directly from
`.github/workflows/tests.yaml` (lines 9-11) and holds regardless of the
exact duration. The argument does not depend on "3 minutes" being precise.
The risk is only that a specific quantitative claim in a permanent process
doc names an incident whose timing is not recorded anywhere committed, so a
future challenger would find nothing to point to.

**Smallest safe fix direction:** Either hedge to "minutes later" (the
argument needs no exact figure), or cite the CI run/PR if a URL is
available, or record the observation in the 0670 proof-of-work directory so
the claim has a home.

**Automated check that would catch it:** None — a test cannot verify a
historical CI run duration from the repository contents.

## Candidate knowledge-base entries

- **Title:** `gh pr create` refuses a branch with no commits ahead of base
  - **Tags:** `gh`, `process`
  - **Trigger:** "opening a pull request on a fresh branch with no commits"
  - **Paragraph:** `gh pr create` (with or without `--draft`) fails with
    "GraphQL: No commits between master and `<branch>`" when the head branch
    has no commits ahead of the base — GitHub will not create a PR whose
    diff is empty. A workflow that opens the PR before any implementation
    commit therefore needs a junk seed commit (which pollutes history) or
    must move PR creation to after the first real commit. This is why
    `docs/workflow.md` step 9 now opens the PR after implementation and
    local gates (#704).

(One candidate; the rest of the review is process-specific prose that does
not generalise into a reusable KB entry.)

## Remaining risk areas checked clean or not fully verified

- **Checked clean:**
  - No surviving `--draft` / `gh pr ready` / `gh pr edit` / `step 2.5`
    *instruction* anywhere in tracked docs, `bin/`, `docs/helpers/`,
    `CONTRIBUTING.md`, or `.github/workflows` (only intentional historical
    quotes and the release-draft concept remain).
  - `docs/workflow.md` fence balance (42 fence lines, even); all changed
    files balanced.
  - `ProcessDocsTest` constraints satisfied: four POW file names present in
    `workflow.md` (paragraph moved to end of step 2); `**Trigger:**` present
    in all N-01..N-13 sections; header keyword regex matches.
  - `MarkdownLinkTest` internal links and anchors resolve (intro same-file
    anchor intact; no new links introduced in the changed prose).
  - `ChangelogStructureTest` operates on `CHANGELOG.md`, which this diff does
    not touch — unaffected.
  - Changelog entry #5 follows the file's Format (Date/Issue/PR/What/Why/
    Success criterion/Outcome), continues the #1-#4 numbering, uses the
    `### #N —` heading shape, and `PR: TBD` is consistent with #4's
    `PR: (this PR)` placeholder style.
  - N-11 and N-13 are untouched and intact; N-12's `extractNoticeSection`
    regex still captures the section with its `**Trigger:**` marker.
  - The 0704 `code-decision-1.md` and `findings-coder.md` are readable,
    honest about uncertainty (dedicated "Things I was unsure about" and
    "Weak spots" sections), and carry file:line references with suggested
    fixes for the out-of-scope observations.

- **Not fully verified (residual observations, not findings):**
  - The "3 minutes later" duration (F-3) — structurally sound, exact figure
    uncorroborated.
  - `docs/workflow.md:371` step 9 prose: "The issue is linked from the first
    push regardless" is slightly ambiguous ("first push" of what — the
    branch or the PR?), but the core claim (`closingIssuesReferences` comes
    from the body's `Closes #N` line) is accurate. Not a factual error.
  - `docs/workflow.md:370` quotes the GraphQL error without the "GraphQL:"
    prefix ("No commits between master and `<branch>`"), while
    `docs/process-notices.md:289` and the changelog include the prefix.
    Both forms identify the same error; a quoting-style difference, not a
    discrepancy.
  - The Quick Reference keeps `git push -u origin "$branch"` at step 2
    (line 686). Before this change that push supported step 2.5's draft-PR
    creation; with 2.5 gone it has no purpose in the QR (the branch is not
    needed on origin until step 3 pushes the implementation). Harmless
    (pushing an empty branch is a no-op) and the task explicitly required
    keeping it; the coder self-reported the tension in `findings-coder.md`
    weak spot #4. Flagging it as a finding would second-guess a task
    mandate, so it is recorded here only as an observation.
