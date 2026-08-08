# Task for delegate

Triage security issues in the crazy-goat/workerman-bundle GitHub repo (local checkout: /Users/piotr.halas/work/workerman-bundle).

Open security-labeled issues: #570, #581, #582, #583, #584, #586, #587.

For EACH issue run: gh issue view <N> --repo crazy-goat/workerman-bundle --json number,title,body,labels,state,comments,createdAt
Then do a quick local code check for the 4 most implementable ones (grep the repo for the mentioned classes/methods, e.g. StaticFilesMiddleware, SfxDownloader, ConfigCache guard, cookie handling, master-process detection code) so the recommendation reflects real, locally-fixable code.

Return a RANKED shortlist (max 3) with for each: number, title, severity, one-paragraph rationale, rough implementation difficulty (easy/medium/hard), and a recommended branch name (fix/issue-<N>-<kebab>). Recommend the single best one to implement now — prefer a genuine vulnerability that is NOT just documentation, with clear scope and moderate difficulty. Also note what files would be touched.

## Acceptance Contract
Acceptance level: checked
Completion is not accepted from prose alone. End with a structured acceptance report.

Criteria:
- criterion-1: Implement the requested change without widening scope
- criterion-2: Return evidence sufficient for an independent acceptance review

Required evidence: changed-files, tests-added, commands-run, residual-risks, no-staged-files

Review gate: required by reviewer.

Finish with a fenced JSON block tagged `acceptance-report` in this shape:
Use empty arrays when no items apply; array fields contain strings unless object entries are shown.
`criteriaSatisfied[].status` must be exactly one of: satisfied, not-satisfied, not-applicable.
`commandsRun[].result` must be exactly one of: passed, failed, not-run.
`manualNotes` and `notes` are optional strings; an empty string means no note and does not satisfy `manual-notes` evidence.
```acceptance-report
{
  "criteriaSatisfied": [
    {
      "id": "criterion-1",
      "status": "satisfied",
      "evidence": "specific proof"
    },
    {
      "id": "criterion-2",
      "status": "satisfied",
      "evidence": "specific proof"
    }
  ],
  "changedFiles": [
    "src/file.ts"
  ],
  "testsAddedOrUpdated": [
    "test/file.test.ts"
  ],
  "commandsRun": [
    {
      "command": "command",
      "result": "passed",
      "summary": "short result"
    }
  ],
  "validationOutput": [
    "validation output or concise summary"
  ],
  "residualRisks": [
    "none"
  ],
  "noStagedFiles": true,
  "diffSummary": "short description of the diff",
  "reviewFindings": [
    "blocker: file.ts:12 - issue found, or no blockers"
  ],
  "manualNotes": "anything else the parent should know"
}
```