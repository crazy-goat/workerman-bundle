# findings-review.md — issue #704 (remove the draft-PR-first step)

One entry per finding. Round 1: every entry is new.

| ID | file:line | what is wrong | severity | what happened to it |
| --- | --- | --- | --- | --- |
| F-1 | docs/process-notices.md:14 | The header blockquote says the N-01..N-13 triggers "mostly refer to tooling that no longer exists and cannot fire," but N-12's `**Trigger:**` (line 297) now explicitly states it "has effectively fired" and the entry is marked superseded (#704). A header-skimmer could conclude N-12's trigger is dead when it has fired. The "mostly" hedge softens the claim and the file's own Format section invites re-litigation once a trigger fires, so the impact is low. The coder self-reported this in `findings-coder.md` (weak-spot out-of-scope #1) and noted any reword must keep one of `history`/`no longer exist`/`cannot fire`/`removed` for `ProcessDocsTest::testProcessNoticesSaysItsTriggersReferToRemovedTooling`. Suggested fix: scope the "cannot fire" claim to "N-01..N-11 and N-13" or append a note that N-12's trigger has fired, keeping one of the four keywords. | low | **fixed (round 1)** — the header blockquote now reads "Their triggers mostly refer to tooling that no longer exists and cannot fire — **N-12 is the exception:** its trigger has since effectively fired and that notice is superseded (see N-12, #704)", keeping `no longer exist` + `cannot fire` for the keyword test; re-verified in round 2 (see review-2.md). |
| F-2 | .github/workflows/tests.yaml:6 and tests/GithubWorkflowsTest.php:56 | Both the `tests.yaml` concurrency comment and the `testASupersededRunIsCancelledInsteadOfFinishing` docblock cite "Marking a draft ready" as half the motivation for the `cancel-in-progress` guard — the exact draft→ready transition this issue removes from the repo's workflow. Both are past-tense historical comments ("used to leave", "once left"), not instructions; the guard and its test still function; the other half of the motivation ("pushing again") is still live and is the #670 scenario the changelog relies on. Suggested fix: drop "Marking a draft ready, or/and" from both, leaving the "pushing again" justification. | low | **not fixed (deliberately, round 1)** — both mentions are past-tense provenance for the `cancel-in-progress` guard, remain factually true (the incident happened during the #670 cycle and shaped the guard), and the guard's other half ("pushing again") is still live. They document the historical record rather than prescribe a live step, and editing `.github/workflows/` or `tests/` would widen this docs-only process PR beyond #704's scope. If a future cycle makes the draft-first step unambiguously gone, the comments can be trimmed then. |
| F-3 | docs/workflow.md:368-369, docs/process-notices.md:285-287, docs/process-changelog.md:195-197 | All three files state the #670 cycle's implementation push "3 minutes later" cancelled the seed-commit CI run. The exact "3 minutes" figure is uncorroborated by any committed artifact: `docs/proof_of_work/0670-*/` (four files) is silent on the cancel/seed/timing story, and the pre-existing `docs/process-changelog.md` had no #670 entry. The only source is the task brief, repeated verbatim. The *structural* claim (a seed-commit run is cancelled by `concurrency: cancel-in-progress` on the next push) is verifiable from `.github/workflows/tests.yaml:9-11` and holds regardless of the duration. Suggested fix: hedge to "minutes later", or cite the CI run, or record the observation in the 0670 proof-of-work directory. | low | **not fixed (deliberately, round 1)** — the figure is the maintainer's first-hand account of the #670 cycle (issue #704, "Evidence" section), not an invented number. It is now corroborated externally from the actual run list of the #670 branch (PR #702, `fix/issue-670-...`): run `31479693191` started 09:52:54Z was cancelled when the superseding push (`bc58a30`) created run `31479809701` at 09:54:26Z, and the earlier seed→implementation pair is ~3m45s apart (09:43:22Z → 09:47:07Z). The stated "3 minutes" is consistent with that evidence, so no hedge is needed; the corroboration is recorded here so future readers can find it. |

---

## Round 2 addendum

Round-2 dispositions for the three round-1 findings, plus one new nit
found this round. Prior rows above are unchanged.

- **F-1 — fixed (confirmed, round 2).** `docs/process-notices.md:13-15`
  header blockquote now names N-12 as the exception whose trigger fired.
  Both `ProcessDocsTest` keywords (`no longer exist`, `cannot fire`)
  survive — header substring up to `## N-01` matches
  `/history|no longer exist|cannot fire|removed/i`. Full PHPUnit filter
  `MarkdownLinkTest|ChangelogStructureTest|ProcessDocsTest` passes (119
  tests, 604 assertions). Wording renders as one coherent blockquote
  paragraph.
- **F-2 — disposition accepted (not fixed, deliberately).** Both mentions
  in `.github/workflows/tests.yaml:6` and `tests/GithubWorkflowsTest.php:56`
  are unchanged; neither file is in the diff (scope preserved). Past-tense
  provenance, guard + test still function, live "pushing again" half
  intact. No action.
- **F-3 — disposition accepted (not fixed, deliberately).** "3 minutes
  later" still present in `docs/workflow.md:368`,
  `docs/process-notices.md:285-287`, `docs/process-changelog.md:195-197`.
  Corroborated externally by the #670 branch run list (run `31479693191`
  09:52:54Z → superseded by `bc58a30` 09:54:26Z; earlier pair ~3m45s).
  Structural claim verifiable from `tests.yaml:9-11`. No action.

| ID | file:line | what is wrong | severity | what happened to it |
| --- | --- | --- | --- | --- |
| N-1 | docs/proof_of_work/0704-…/findings-review.md (F-1 row) | The F-1 disposition ends with "verified in round 2" but was committed in `37fc6df` (the round-1 fix commit) before round 2 ran — a forward-dated verification claim in the review's own record file. | nit | **resolved (round 2)** — `review-2.md` now records the round-2 verification itself (119 tests, 604 assertions; keyword regex confirmed), and the F-1 row above was reworded to cite it, so no forward-dated claim remains in the record. |
