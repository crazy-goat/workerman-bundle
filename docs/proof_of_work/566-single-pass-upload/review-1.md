# Review 1 — Issue #566: Single-pass upload processing

**Trigger check (review-critical):** matched — >200 changed lines
(599 insertions across the diff). Not src/Http, not process supervision,
not a public interface break (only additive public methods on
`FileUploadValidator`). Reviewed at review-critical depth anyway per the
>200-lines rule.

## Helpers KB consultation

Loaded the tag indexes of `docs/helpers/faq.md` / `docs/helpers/decisions.md`;
tags matching this diff: `performance`, `http`, `phpstan`, `tests/coverage`,
`benchmarks`. Entries read: DEC-013, DEC-018, FAQ-010, FAQ-011, FAQ-014,
FAQ-028, FAQ-029.

- **DEC-013 (fast-path gates on parsers):** the `$files !== []` gate in
  `toSymfonyRequest()` is preserved unchanged (src/DTO/RequestConverter.php:97).
  The gate cannot false-negative: an empty files array means there is nothing
  to validate or convert. **Compliant.**
- **FAQ-010/011 (coverage):** gates not lowered; new code is covered except
  two defensive lines (finding 1).
- **FAQ-028/029, DEC-018, FAQ-014:** not applicable to this diff.
- No violations of documented decisions found.

`findings-review.md` did not exist before this round — this is round 1, no
prior open findings to disposition.

## Behavioral equivalence analysis (old two-pass vs new single-pass)

Old observable behavior = `FileUploadValidator::validate()` (throws first on
any malformed shape) followed by `processFiles()` (only ever saw valid
shapes). I traced every shape class against `git show origin/master:` sources:

| Shape | Old behavior | New behavior | Match |
|---|---|---|---|
| Top-level non-array value | `expectedArrayError(field)` from validate | `expectedArrayError(field)` from processFileNode | ✅ identical class + message |
| Single entry missing required field | `assertRequiredFields` message (was `validateSingleFileArray`) | same helper, verbatim message | ✅ |
| File list, valid entries | recursive convert, keys 0..n | `$list[$index]` build (isFileList ⇒ list keys) | ✅ identical output |
| File list, non-array later entry (`[entry, 'foo']`) | `expectedArrayError(f[i])` from validateFileList | `expectedArrayError(f[i])` from the is_array guard | ✅ (but see finding 1: uncovered) |
| File list, entry array w/o required fields | missing-required-field error | same via assertRequiredFields | ✅ |
| Nested assoc, valid children | recursive convert | nested branch convert | ✅ identical output |
| Nested assoc, non-array child | `expectedArrayError(f[sub])` | same | ✅ |
| Nested assoc, child neither list nor entry | `unrecognizedStructureError(f[sub])` | same | ✅ (incl. the `['not an array']` case from findings-coder.md) |
| Deeper nesting (`a[b][c]`) | validate threw unrecognized at `a[b]`… actually `f[a]` | same | ✅ |
| Empty array value | `[]` passthrough (both passes no-op) | `[]` via zero-iteration nested branch | ✅ |
| Scalar-first list (`['a','b']`) | not a list (isFileList false) → nested-assoc → `expectedArrayError(f[0])` | same dispatch order (isFileList → isSingleFileEntry → nested) | ✅ |
| tmp_name is an array with all keys present | validate passed (array_key_exists only) → TypeError in UploadedFile | identical TypeError | ✅ same defect, not a regression |

Message formats were compared byte-for-byte: `expectedArrayError`,
`unrecognizedStructureError`, and `assertRequiredFields` are verbatim
extractions of the old inlined strings (including the two-space indentation
fix in docblocks being comment-only). The old `processFiles()` copy of the
"expected array" message used bare keys while validate used dotted paths, but
validate always threw first, so the dotted path was the only observable form —
the new code uses dotted paths everywhere. **Equivalent.**

`isFileList`/`isSingleFileEntry` are disjoint (a list cannot have string keys
`tmp_name`/`name`), so the isFileList-first dispatch order matches validate()'s
order, which was the effective old order.

Shape-recognition logic: predicates live only in `FileUploadValidator`;
`RequestConverter` calls them. **No duplicated shape recognition** —
acceptance criterion met. (The list-*iteration* block is duplicated, finding
4 — that is not shape recognition.)

## Checks run

- `vendor/bin/phpstan analyse` (level 8, phpstan.neon.dist) — **OK, no errors**
  (255 files).
- `vendor/bin/php-cs-fixer fix -v --dry-run` — **0 of 255 files fixable**;
  PSR-12 clean.
- `vendor/bin/phpunit tests/RequestConverterTest.php tests/FileUploadValidatorTest.php`
  — **125 tests, 614 assertions, OK** (1 environment warning: xdebug coverage
  mode not set; pre-existing, unrelated).
- No `.github/workflows/*` changes, so FAQ-032 full-suite sweep not triggered
  (spot-ran the two touched test classes plus the validator suite above).

## Findings

1. **low — two defensive `is_array` guards in the file-list branches are
   uncovered by tests.** src/DTO/RequestConverter.php:489-491 (top-level
   `isFileList` branch) and :519-521 (nested-branch list) are only reachable
   with a list whose *first* element is an array but a *later* element is not,
   e.g. `['files' => [0 => $validEntry, 1 => 'foo']]`.
   `testNonArrayElementInFileListThroughConverter`
   (tests/RequestConverterTest.php:448) does **not** reach them:
   `isFileList(['not an array entry'])` is false because `$data[0]` is not an
   array, so that test goes through the nested-associative branch instead.
   Check that could catch this: a unit test. Fix: add a case with
   `[$validEntry, 'foo']` at top level and one nested. Status: open.
2. **nit — misleading docblock on
   `testNonArrayElementInFileListThroughConverter`.** It claims to exercise "a
   non-array element inside an indexed file list (files[])", but the input is
   classified as a nested associative container, not a file list (see finding
   1). The assertion still passes via the nested path. Reword or change the
   input to `[$validEntry, 'foo']` so it actually covers the file-list guard.
   Status: open.
3. **low — exception-priority change for doubly-corrupt input.** Old order
   (validate everything, then convert) meant a structurally malformed field
   always produced `FileUploadValidationException` even when an
   earlier-iterated field had a nonexistent `tmp_name`. The new interleaved
   pass constructs `UploadedFile` as it goes, and Symfony's
   `File::__construct` throws `FileNotFoundException` when `error ===
   UPLOAD_ERR_OK` and the path is missing (verified in
   vendor/symfony/http-foundation/File/File.php:35). So for input that is both
   malformed *and* contains an earlier valid-shape entry with a dangling
   tmp_name, the exception type changes from `FileUploadValidationException`
   to `FileNotFoundException`. Reachable only with corrupt tmp paths that real
   Workerman never produces (its tmp files exist); all structurally-malformed-
   only inputs still throw the identical exception. Acceptable, but worth a
   line in the PR description. Status: open (advisory).
4. **nit — list-building block duplicated in `processFileNode()`.**
   src/DTO/RequestConverter.php:485-495 and :515-525 repeat the same
   ~10-line list-conversion loop. Acknowledged in code-decision-1.md
   ("Uncertainties"). A private `processFileList(string $fieldName, array
   $list): array` helper would remove it. Not a violation of the "no
   duplicated shape-recognition" criterion (predicates are shared). Status:
   open.
5. **pending — benchmark numbers must land in the PR description.**
   Acceptance criterion: "`RequestConverterBench` multipart subject numbers
   before/after in the PR description". No PR exists yet (`gh pr list --head
   perf/issue-566-…` → empty); the numbers currently live only in
   code-decision-1.md (5.53µs ��� 5.67µs, within noise, xdebug on). Ensure they
   are copied into the PR body at creation. Cannot be caught by automation in
   this repo. Status: open (process).

## What is verified good

- Single traversal: `FileUploadValidator::validate()` call removed from the
  hot path; `processFiles()` is the only walk. Confirmed no other `src/`
  caller of `validate()`.
- Fast path `$files !== []` preserved verbatim (RequestConverter.php:97).
- `validate()` retained with byte-identical messages; existing
  `FileUploadValidatorTest` untouched and green.
- BC: only additive public static methods on `FileUploadValidator`;
  `RequestConverter` public surface unchanged.
- CHANGELOG entry under `[Unreleased]` → `Changed`, accurate description,
  correct issue link ([#566]).
- Contract tests cover single file, `files[]`, nested assoc, and list-in-
  nested-assoc (`gallery[images][]`) output shapes, plus the "expected array"
  and "unrecognized structure" single-traversal error paths.
- PHPStan level 8 and php-cs-fixer both clean.

## KB candidate entries (proposed, not written)

1. **Title:** Single-pass upload processing (validate-while-converting)
   **Tags:** performance, http, architecture
   **Trigger:** changing file-upload validation or processFiles() in
   RequestConverter
   **Body:** `RequestConverter::processFiles()` performs shape recognition and
   required-field validation in one traversal, using `FileUploadValidator`'s
   predicates (`isSingleFileEntry`, `isFileList`) and error helpers
   (`assertRequiredFields`, `expectedArrayError`,
   `unrecognizedStructureError`) as the single source of truth (#566). Do not
   reintroduce a separate `validate()` pass before conversion; keep the
   `$files !== []` fast path. `validate()` remains a standalone API pinned by
   `FileUploadValidatorTest`.
2. **Title:** Converter must mirror the validator's shape dispatch exactly
   **Tags:** http, tests
   **Trigger:** adding a new upload shape or changing
   isSingleFileEntry/isFileList
   **Body:** When validation and conversion share one pass, the converter's
   nested-container branch must replicate `validateNestedAssociative`'s
   three-way dispatch (isFileList → isSingleFileEntry → unrecognizedStructure
   error) instead of recursing blindly. The trap case is
   `['field' => ['subfield' => ['not an array']]]`: the correct error is
   "unrecognized structure" for `field[subfield]`, not "expected array, got
   string". Also note `isFileList()` returns false for lists whose first
   element is a scalar — such inputs are classified as nested containers, so
   "non-array element in a file list" tests must use `[$validEntry, 'foo']`
   to actually hit the list guard.
3. **Title:** Single-pass conversion changes exception priority for
   doubly-corrupt uploads
   **Tags:** http
   **Trigger:** reordering validation vs UploadedFile construction
   **Body:** Validate-first ordering guaranteed `FileUploadValidationException`
   beat Symfony's `FileNotFoundException` (thrown by `File::__construct` for a
   missing tmp_name when error=UPLOAD_ERR_OK). Validate-while-converting makes
   the exception depend on field iteration order for inputs that are both
   structurally malformed and contain dangling tmp paths. Accepted in #566 as
   unreachable via real Workerman; revisit if tmp_name sourcing ever changes.

## Verdict

Approve with minor follow-ups: findings 1+2 (one small test addition) should
be fixed in this PR; findings 3–5 are advisory/process.
