# Review 2 — Issue #566: Single-pass upload processing

**Trigger check (review-critical):** matched — >200 changed lines
(451 insertions vs origin/master). Not src/Http, not process supervision,
public-interface change is additive only (new public static helpers on
`FileUploadValidator`; `RequestConverter` surface unchanged). Reviewed at
review-critical depth per the >200-lines rule.

## Helpers KB consultation

Loaded the tag indexes of `docs/helpers/faq.md` / `docs/helpers/decisions.md`;
tags matching this diff: `http`, `security`, `performance`, `phpstan`,
`tests/coverage`, `benchmarks`. Entries read: DEC-005, DEC-006, DEC-007,
DEC-010, DEC-013, DEC-015, DEC-016, DEC-017, FAQ-010, FAQ-011, FAQ-014,
FAQ-028, FAQ-029.

- **DEC-013 (fast-path gates on security-relevant parsers):** the
  `$files !== []` gate at src/DTO/RequestConverter.php:97 is preserved
  unchanged; an empty files array has nothing to validate or convert, so no
  false negative is possible. **Compliant.**
- **DEC-006 / DEC-016 (no loosening of hardening):** validation strictness is
  unchanged — every malformed shape still throws
  `FileUploadValidationException` with byte-identical messages. **Compliant.**
- **DEC-007 / FAQ-010 / FAQ-011 (coverage):** floor untouched; clover run for
  this round shows every line of `processFileNode()` (479–532) and
  `processFileEntry()` (544–557) covered, including both previously-uncovered
  guard throws (490, 520). **Compliant.**
- **FAQ-028 (phpbench):** numbers still live only in code-decision-1.md; PR
  does not exist yet (finding 5, process, still open).
- **DEC-010, DEC-015 (cookies), DEC-017 (error_log), FAQ-014, FAQ-029:** not
  applicable to this diff.
- No violations of documented decisions found.

## Disposition of round-1 findings

| # | Disposition | Evidence |
|---|---|---|
| 1 (uncovered `is_array` guards, src/DTO/RequestConverter.php:489-491, :519-521) | **fixed** | Commit 67314f7 rewrote `testNonArrayElementInFileListThroughConverter` to `[0 => $validEntry, 1 => 'not an array']` and added `testNonArrayElementInNestedFileListThroughConverter` (`gallery[images]` with the same shape). Verified independently with a clover run (`XDEBUG_MODE=coverage vendor/bin/phpunit tests/RequestConverterTest.php tests/FileUploadValidatorTest.php --coverage-clover`): line 490 (top-level guard throw) count=1, line 520 (nested guard throw) count=1. The guards are now genuinely exercised, not just assertion-passing via another path. |
| 2 (misleading docblock, tests/RequestConverterTest.php:443-447) | **fixed** | The input is now a genuine file list (`isFileList` true: list with array at index 0), and the docblock was expanded to explain why position 0 must be a valid entry (a scalar first element would route to the nested-associative branch). Docblock now matches behavior. |
| 3 (exception-priority change for doubly-corrupt input) | **still present (advisory, accepted)** | Behavior unchanged by 67314f7 (test-only commit). No PR exists yet (`gh pr list --head perf/issue-566-…` empty), so the "document in PR" advice is not yet actionable. See new finding 6 for the related CHANGELOG wording. |
| 4 (duplicated list-conversion loop, :485-495 vs :515-525) | **still present (acknowledged)** | Code unchanged; acknowledged in code-decision-1.md. Remains a nit. |
| 5 (bench numbers must land in PR body) | **still present (process)** | `gh pr list --head perf/issue-566-upload-structure-traversed-twice-per-mul` is empty — no PR yet. Numbers (5.53µs → 5.67µs, within noise, xdebug on) still only in code-decision-1.md. |

## Equivalence re-verification vs origin/master

Re-traced against `git show origin/master:src/DTO/RequestConverter.php` and
`git show origin/master:src/Validator/FileUploadValidator.php`:

- The old observable order was validate-everything-then-convert; the new
  interleaved order is equivalent for every input class enumerated in
  review-1.md's shape table. The round-1 table still holds; 67314f7 touched
  only tests.
- `isFileList()` uses `array_is_list($data) && is_array($data[0])`
  (src/Validator/FileUploadValidator.php:44-52), so list keys are guaranteed
  sequential ints and `$list[$index]` produces the identical output shape as
  the old recursive key preservation.
- An entry inside a file list that is itself a nested container hits
  `assertRequiredFields` → "missing required field", exactly as the old
  `validateFileList` → `validateSingleFileArray` path did. Equivalent.
- `FileUploadValidator::validate()` has no remaining callers in `src/`
  (grep confirms; only `tests/FileUploadValidatorTest.php` calls it) —
  single traversal confirmed; `validateSingleFileArray` fully removed with no
  dangling references.
- BC: only additive public static methods (`assertRequiredFields`,
  `expectedArrayError`, `unrecognizedStructureError`); `validate()` retained
  with byte-identical messages; `tests/FileUploadValidatorTest.php` is
  untouched vs master and green.
- Test hygiene: temp files created by the new tests are registered in
  `$tempFiles` and unlinked in `tearDown()` (tests/RequestConverterTest.php:25-32)
  — no resource leak, including for the exception-expecting tests.

## Checks run (this round)

- `XDEBUG_MODE=coverage vendor/bin/phpunit tests/RequestConverterTest.php tests/FileUploadValidatorTest.php --coverage-clover …` — **126 tests, 616 assertions, OK** (was 125 in round 1; +1 new nested-list test).
- Clover line coverage of src/DTO/RequestConverter.php: every statement in
  `processFileNode` (479–532) and `processFileEntry` (544–557) has count ≥ 1;
  guard throws at 490 and 520 each count=1. Zero uncovered lines in the new
  code.
- `vendor/bin/phpstan analyse` (level 8, phpstan.neon.dist) — **OK, no errors** (255 files).
- `vendor/bin/php-cs-fixer fix -v --dry-run` — **0 of 255 files fixable**; PSR-12 clean.
- No `.github/workflows/*` changes → FAQ-032 full-suite sweep not triggered.
- CHANGELOG: entry present under `[Unreleased]` → `Changed`, correct issue
  link ([#566]); see finding 6 for one wording nit.

## New findings (this round)

6. **nit — CHANGELOG absolute claim "exception types … are identical" is
   overstated for the doubly-corrupt case.** CHANGELOG.md (Unreleased →
   Changed, #566 entry) says "Error messages, exception types, and
   `UploadedFile` output structure are identical", but round-1 finding 3
   documented one accepted deviation: for input that is both structurally
   malformed *and* contains an earlier valid-shape entry with a dangling
   tmp_name, the interleaved pass can throw Symfony's `FileNotFoundException`
   before `FileUploadValidationException`. Suggest softening to "…are
   identical for all well-formed and structurally malformed inputs" (or
   dropping "exception types"), so the changelog doesn't contradict the PR
   note that finding 3 asks for. Catchable by: human review only. Severity:
   nit.

## KB candidate entries (proposed, not written)

The three candidates from review-1.md still stand. One refinement to
candidate 2 now that the fix is verified by coverage rather than probes:

1. **Title:** Converter must mirror the validator's shape dispatch exactly
   **Tags:** http, tests
   **Trigger:** adding a new upload shape or changing
   isSingleFileEntry/isFileList
   **Body:** (as review-1, plus:) `isFileList()` requires `array_is_list` AND
   an array at index 0 — a test for "non-array element in a file list" must
   use `[$validEntry, 'foo']`; `['foo', $validEntry]` or `['foo']` classify
   as nested containers and never reach the list guard. Verify guard coverage
   with a clover run, not just by the assertion passing — the same exception
   message is reachable from three different branches.

## Verdict

Round-1 findings 1 and 2 are verifiably fixed (coverage-backed). No
regressions: 126 tests green, PHPStan 8 clean, PSR-12 clean, error behavior
equivalent to master for every reachable input class. Findings 3–5 remain
open as advisory/process items pending PR creation. One new nit (finding 6,
CHANGELOG wording). **Approve** — merge-ready once findings 3/5 (and ideally
6) are handled at PR-creation time.
