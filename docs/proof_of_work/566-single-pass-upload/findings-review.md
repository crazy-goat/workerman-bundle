# Findings — Review — Issue #566

## Round 1 (review-1.md)

| # | file:line | what is wrong | severity | status |
|---|---|---|---|---|
| 1 | src/DTO/RequestConverter.php:489-491 and :519-521 | The `is_array` guards inside the `isFileList` branches are uncovered: `testNonArrayElementInFileListThroughConverter` uses `['not an array entry']`, which `isFileList` rejects (first element not an array), so it exercises the nested-assoc path. Only `[0 => $validEntry, 1 => 'foo']` reaches these throws. Catchable by: a unit test. | low | fixed — test rewritten to `[0 => $validEntry, 1 => 'foo']` (top-level guard) plus a new nested-list test `testNonArrayElementInNestedFileListThroughConverter`; both confirmed to hit the guards via fwrite probes (`files[1]`, `gallery[images][1]`) |
| 2 | tests/RequestConverterTest.php:443-447 | Docblock claims the test covers "a non-array element inside an indexed file list (files[])" but the input is dispatched as a nested associative container, not a file list (see #1). Reword the docblock or change the input to `[$validEntry, 'foo']`. | nit | fixed — input changed to `[0 => $validEntry, 1 => 'foo']` so it is a genuine file list; docblock expanded to explain the isFileList requirement |
| 3 | src/DTO/RequestConverter.php:481-532 (processFileNode) | Exception priority changed for doubly-corrupt input: validate-first ordering always yielded `FileUploadValidationException`; interleaved convert-while-validating can yield Symfony `FileNotFoundException` first when an earlier-iterated valid-shape entry has a nonexistent tmp_name (File.php:35 throws when error=UPLOAD_ERR_OK and path missing). Unreachable via real Workerman tmp files; structurally-malformed-only inputs are byte-identical. Advisory — document in PR. | low | open |
| 4 | src/DTO/RequestConverter.php:485-495 vs :515-525 | List-conversion loop duplicated between the top-level `isFileList` branch and the nested-container branch. Not shape-recognition duplication (predicates shared), so acceptance criterion met, but a `processFileList()` helper would remove it. Acknowledged in code-decision-1.md. | nit | open |
| 5 | (process) docs/proof_of_work/566-single-pass-upload/code-decision-1.md:62-68 | Acceptance criterion "RequestConverterBench multipart subject numbers before/after in the PR description" is unverifiable: no PR exists yet (`gh pr list --head perf/issue-566-…` is empty). Numbers (5.53µs → 5.67µs, within noise, xdebug on) must be copied into the PR body at creation. | low | open |

## Round 2 (review-2.md)

| # | file:line | what is wrong | severity | status |
|---|---|---|---|---|
| 1 | src/DTO/RequestConverter.php:489-491 and :519-521 | (round 1) uncovered `is_array` guards in the file-list branches | low | fixed — verified via clover coverage this round: line 490 and line 520 each count=1 after commit 67314f7 (`[$validEntry, 'foo']` top-level + nested `gallery[images]` variant) |
| 2 | tests/RequestConverterTest.php:443-447 | (round 1) docblock claimed file-list coverage the input did not provide | nit | fixed — input is now a genuine file list and the docblock explains the isFileList position-0 requirement |
| 3 | src/DTO/RequestConverter.php:479-532 (processFileNode) | (round 1) exception-priority change for doubly-corrupt input (FileNotFoundException can now precede FileUploadValidationException) | low | still present — advisory, accepted; unchanged by 67314f7 (test-only); no PR exists yet to carry the documentation note |
| 4 | src/DTO/RequestConverter.php:485-495 vs :515-525 | (round 1) duplicated list-conversion loop between the two isFileList branches | nit | still present — acknowledged in code-decision-1.md; code unchanged |
| 5 | (process) code-decision-1.md:62-68 | (round 1) bench numbers (5.53µs → 5.67µs) must be copied into the PR body | low | still present — `gh pr list --head perf/issue-566-…` empty; no PR yet |
| 6 | CHANGELOG.md (Unreleased → Changed, #566 entry) | New: "exception types … are identical" is absolute, but finding 3 documents one accepted exception-type change for doubly-corrupt input; soften the wording or drop "exception types" so the changelog doesn't contradict the PR note | nit | open |

## Round 2 disposition (main session)

- #6 (nit, CHANGELOG absolutism): **fixed** — softened to "identical for all well-formed and structurally malformed inputs" (CHANGELOG.md, [Unreleased] → Changed).
- #3 (exception-priority change): **deliberately not fixed in code** — accepted; will be documented in the PR body at creation.
- #4 (duplicated list-conversion loop): **deliberately not fixed** — acknowledged in code-decision-1.md; `processFileList()` helper is a cosmetic refactor for a later cycle.
- #5 (bench numbers into PR body): **process note** — numbers (5.53 µs → 5.67 µs, noise; single-file subject) will be pasted into the PR body at creation.
