# review-2.md — issue #704 (remove the draft-PR-first step)

Round 2 critical review of the current branch state
`process/issue-704-remove-the-draft-pr-first-step-workflow`
(`git diff origin/master..HEAD`, 7 files: `docs/process-changelog.md`,
`docs/process-notices.md`, `docs/workflow.md`, and the four files under
`docs/proof_of_work/0704-remove-the-draft-pr-first-step-workflow/`).

Round 1 (`review-1.md`) found three low findings (F-1, F-2, F-3). Commit
`37fc6df` recorded dispositions for all three and applied the F-1 fix. This
round revisits every round-1 finding against the current tree, then hunts
for new issues in the round-1-fix diff and the new proof-of-work files.

## Earlier findings (round 1 → round 2 dispositions)

- **F-1 — fixed.** `docs/process-notices.md:13-15` header blockquote now
  reads: "Their triggers mostly refer to tooling that no longer exists and
  cannot fire — **N-12 is the exception:** its trigger has since
  effectively fired and that notice is superseded (see N-12, #704). They
  are kept because the reasoning is still worth reading…". Both
  `ProcessDocsTest` keywords (`no longer exist`, `cannot fire`) survive —
  verified by extracting the header substring (`substr` up to `## N-01`)
  and matching `/history|no longer exist|cannot fire|removed/i` → match on
  `history` (and the other three). The full PHPUnit filter
  `MarkdownLinkTest|ChangelogStructureTest|ProcessDocsTest` passes: 119
  tests, 604 assertions. The wording renders as one coherent blockquote
  paragraph (em-dash aside + colon elaboration + parenthetical
  cross-reference); the pre-existing soft-wrap "because the / reasoning"
  is unchanged from before the fix and renders as a space. No new issue.
- **F-2 — disposition accepted (not fixed, deliberately).**
  `.github/workflows/tests.yaml:6` still reads "Marking a draft ready, or
  pushing again while a run is still going, used to leave two full
  matrices…" and `tests/GithubWorkflowsTest.php:56` still reads "Marking a
  draft ready and pushing a moment later once left two full matrices…".
  Neither file is in `git diff origin/master..HEAD` — scope preserved
  (this stays a docs-only PR). Both are past-tense provenance
  ("used to leave" / "once left"), the `cancel-in-progress` guard and its
  test still function, and the live half of the motivation ("pushing
  again") is the #670 scenario the changelog entry relies on. The
  round-1 rationale (editing `.github/` or `tests/` would widen a
  docs-only process PR) holds. No action.
- **F-3 — disposition accepted (not fixed, deliberately).** The "3
  minutes later" figure is still present in all three locations
  (`docs/workflow.md:368`, `docs/process-notices.md:285-287`,
  `docs/process-changelog.md:195-197`). The round-1 disposition recorded
  external corroboration from the #670 branch run list: run
  `31479693191` started 09:52:54Z, cancelled when the superseding push
  `bc58a30` created run `31479809701` at 09:54:26Z (~1m32s), with the
  earlier seed→implementation pair ~3m45s apart (09:43:22Z → 09:47:07Z).
  The stated "3 minutes" is consistent with that evidence. The structural
  claim is verifiable from `.github/workflows/tests.yaml:9-11`
  (`cancel-in-progress: true`) regardless of duration. No action.

## Overall verdict

**Clean for round 2.** The only source change since round 1 is the F-1
header rewording in `docs/process-notices.md` (commit `37fc6df`); the
remaining hunks of that commit are the two new proof-of-work files
(`review-1.md`, `findings-review.md`). No high or medium findings. One
nit (N-1) in a proof-of-work record file — non-blocking and becoming
retroactively true as this verification proceeds. The change stays
within #704's docs-only scope (no `.github/`, `tests/`, `src/`, `bin/`,
`composer.json`, or `docs/helpers/` touched).

## Verification performed

- **Round-1-fix diff isolation:** `git show 37fc6df` touched exactly three
  files — `docs/process-notices.md` (the 3-line header insert), and the
  two PoW files `review-1.md` + `findings-review.md`. No
  `docs/workflow.md` or `docs/process-changelog.md` regression, no source
  file touched.
- **Scope preserved:** `git diff origin/master..HEAD -- .github/workflows/
  tests/ src/ bin/ composer.json docs/helpers/` → empty. Seven files
  changed total, all docs/PoW.
- **Success criterion (changelog entry #5):** `grep -nE "2\.5|--draft|gh
  pr ready" docs/workflow.md` → exit 1 (no match). The only multi-line
  `gh pr create` code block is at `docs/workflow.md:376` (step 9); the
  Quick Reference one-line echo at line 714 matches it
  (`--title … --body … --base master --assignee @me`).
- **Tests:** `php -d phar.readonly=0 vendor/bin/phpunit --no-coverage
  --filter 'MarkdownLinkTest|ChangelogStructureTest|ProcessDocsTest'` →
  OK (119 tests, 604 assertions). Round 1 reported 115/592; the +4 tests
  / +12 assertions are the four new tracked `.md` PoW files now swept by
  `MarkdownLinkTest`'s data provider (fence balance + link resolution) —
  expected, and it confirms the new files pass the gate rather than
  regressing it.
- **F-1 keyword test (manual):** header substring up to `## N-01` matches
  `/history|no longer exist|cannot fire|removed/i` on `history` (and the
  other three keywords). `testProcessNoticesSaysItsTriggersReferToRemovedTooling`
  passes.
- **N-12 section intact:** `**Trigger:**` marker present in N-12
  (`docs/process-notices.md:297`), unchanged by `37fc6df` (it was set in
  `476a7bd`); `testProcessNoticesContainsEveryNoticeWithATrigger` still
  finds it in all N-01..N-13 sections.
- **Fence balance (all changed + new files):** `docs/workflow.md` 38
  (even), `docs/process-notices.md` 0, `docs/process-changelog.md` 0, and
  all four PoW `.md` files 0 — all balanced.
- **New PoW files — links:** `grep -E '\]\([^h)]+\)'` across the four new
  PoW files → no relative/internal links (no `MarkdownLinkTest` target to
  break); external/`#` references are prose only.
- **F-1 rendering:** the header blockquote is a single paragraph; the
  inserted clause ("— **N-12 is the exception:** its trigger has since
  effectively fired and that notice is superseded (see N-12, #704).")
  reads grammatically as an em-dash aside + colon elaboration. The "see
  N-12, #704" cross-reference is plain text (not a markdown link), which
  is fine — `#704` refers to the GitHub issue and `N-12` to the section
  below in the same file.

## New findings

### N-1 | docs/proof_of_work/0704-…/findings-review.md (F-1 row, "verified in round 2") | forward-dated verification claim | nit

**Evidence:** The F-1 disposition row, committed in `37fc6df`, ends with
"verified in round 2." That commit is the round-1 fix commit — round 2
had not run when the row was written, so the claim predates the
verification it names. The row is in the review's own record file
(`findings-review.md`), and the README for proof-of-work files states
these are "read by a human during review" with "nothing… a script could
usefully check that a reader cannot" — so record accuracy is the only
quality bar, and a forward-dated claim undermines it slightly for a
reader reconstructing the cycle from the commit graph.

**Impact:** Nit. The claim is becoming retroactively true as this round-2
review runs (the keyword match is confirmed above), so the practical
effect is nil. The only residual is temporal honesty in the committed
record.

**Smallest safe fix direction:** Either drop "; verified in round 2" from
the row (the fix description already stands on its own), or rephrase as
"to be verified in round 2" if the forward-looking intent should be kept.
Moot once this round-2 review is committed, since the verification now
exists in `review-2.md`.

**Automated check that would catch it:** None — a test cannot verify that
a prose claim's tense matches the commit's position in the cycle.

## Candidate knowledge-base entries

None. The round-1 candidate (`gh pr create` refuses a branch with no
commits ahead of base) is still the only generalisable lesson and is
already on record from round 1; the round-2 work is verification of a
docs fix and surfaces no new reusable lesson.

## Remaining risk areas checked clean or not fully verified

- **Checked clean:**
  - No source/test/config/helper file touched — scope is strictly
    `docs/` + PoW (7 files).
  - `37fc6df` isolated to the F-1 header reword + 2 PoW files; no
    regression to `workflow.md` or `process-changelog.md` content.
  - Success-criterion grep empty; `gh pr create` block only in step 9;
    Quick Reference echo consistent with step 9.
  - `ProcessDocsTest` constraints hold (keyword regex, per-notice
    `**Trigger:**`, four POW file names in `workflow.md`).
  - `MarkdownLinkTest` passes with the four new PoW `.md` files now in
    its data provider (fence balance + link resolution) — they are clean.
  - F-1 header wording renders as one coherent blockquote paragraph; no
    stranded-word or broken-link artefact introduced.
  - No staged files; working tree clean.

- **Not fully verified (residual observations, not findings):**
  - The F-3 "3 minutes" figure relies on external run-list evidence
    recorded in the round-1 disposition, not on a committed artifact
    inside the 0704 tree. The structural claim is sound regardless.
  - `docs/workflow.md:371` step-9 prose ("The issue is linked from the
    first push regardless") carries the same minor "first push of what"
    ambiguity noted in round 1; the core `closingIssuesReferences` claim
    is accurate. Unchanged by the round-1 fix, not a finding.
