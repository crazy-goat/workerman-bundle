# Findings — Coder — Issue #566

## Biggest problem faced

**Critical behavioral divergence between `validate()` and `processFiles()`**
that would have caused test failures if not carefully handled.

The old `processFiles()` had a completely different shape-recognition
strategy than `validate()`:

- `validate()` used a three-way branch: `isFileList` → `isSingleFileEntry`
  → `validateNestedAssociative` (which itself checks each child with
  `isFileList`/`isSingleFileEntry` and throws "unrecognized structure"
  if neither matches).
- `processFiles()` used a two-way branch: `isSingleFileEntry` → build
  UploadedFile, else → recurse blindly. It did NOT call `isFileList` and
  did NOT produce "unrecognized structure" errors.

Because `validate()` ran first and threw on malformed input, `processFiles()`
never saw invalid data in the old design. When I removed the `validate()`
call, `processFiles()` had to produce the *exact same errors* for *every
malformed shape* — or existing tests would break.

The hardest case was `testNonArrayInIndexedNestedArrayThrowsException`:
input `['field' => ['subfield' => ['not an array']]]`. The validator
threw "unrecognized structure" for `field[subfield]` (because
`['not an array']` is an array but neither a file list nor a single file
entry). A naive single-pass converter would recurse into `['not an array']`
and throw "expected array, got string" instead — breaking the test.

**Fix:** made `processFileNode`'s nested-container branch mirror
`validateNestedAssociative` exactly: check each child with `isFileList` /
`isSingleFileEntry` and throw `unrecognizedStructureError` if neither
matches, rather than recursing blindly.

A second divergence: `processFiles()` passed file-list entries directly to
`new UploadedFile()` without checking `is_array()`. A non-array entry in a
file list (e.g. `['files' => ['not an array']]`) would have caused a
TypeError instead of `FileUploadValidationException`. Fixed by adding an
`is_array` check with `expectedArrayError()` before `processFileEntry()`.

## Discovered bugs / weak spots

### 1. File list entries not type-checked before UploadedFile construction (pre-existing)
- **File:** `src/DTO/RequestConverter.php`, old `processFiles()` line 468
- **Problem:** The old code called `new UploadedFile($value['tmp_name'], ...)`
  on file-list entries without verifying the entry was an array or had the
  required fields. It relied entirely on `validate()` running first. If
  anyone had called `processFiles()` without `validate()`, a malformed file
  list entry would have caused a TypeError or undefined-index notice.
- **Status:** Fixed by this change — `processFileEntry()` now calls
  `assertRequiredFields()` and the `is_array` check is explicit.

### 2. `isFileList` returns false for a list with a non-array first element (by design, but surprising)
- **File:** `src/Validator/FileUploadValidator.php:44-51`
- **Problem:** `isFileList(['not an array'])` returns false because
  `is_array($data[0])` is false. This means a file list like
  `['files' => ['string']]` is treated as a nested associative container,
  not a file list. The validator and converter both handle this correctly
  (throwing "expected array"), but the classification is non-obvious and
  could trip up future maintainers.
- **Suggested fix:** No code change needed — behavior is correct. Consider
  adding a comment to `isFileList()` documenting that a list of scalars is
  intentionally NOT a file list and will be rejected as "unrecognized
  structure" or "expected array" by the callers.

### 3. `validate()` only supports one level of nested associative (by design)
- **File:** `src/Validator/FileUploadValidator.php:144-173`
- **Problem:** `validateNestedAssociative` does not recurse — it checks
  each child with `isFileList`/`isSingleFileEntry` and throws
  "unrecognized structure" if neither matches. So
  `['a' => ['b' => ['c' => {file}]]]` is rejected, even though it could
  be a valid deeply-nested upload. The old `processFiles()` WOULD have
  recursed (it called `self::processFiles($value)` for any non-single-file
  array), but never got the chance because `validate()` threw first.
- **Suggested fix:** If deeply-nested uploads should be supported, both
  `validate()` and `processFiles()` need to agree on a recursion depth
  limit. Currently they agree on "one level" — which is fine, but should
  be documented.

### 4. Benchmark multipart subject uses only a single file (advisory)
- **File:** `benchmarks/RequestConverterBench.php:56-68`
- **Problem:** The `benchMultipartRequest` subject sends one file
  (`test_file`). The second traversal in the old code was trivially cheap
  for one file, so the benchmark shows no meaningful improvement after
  eliminating it. A subject with many nested files (files[], documents[0],
  user[avatar]) would better demonstrate the improvement.
- **Suggested fix:** Add a `benchMultipartMultiFileRequest` subject with
  10+ files across multiple fields and nesting levels.

### 5. xdebug enabled in bench environment
- **File:** environment
- **Problem:** `phpbench` reports `xdebug ✔, opcache ❌`, which makes
  microbenchmark numbers noisy and unreliable. The ±27.82% variance in
  one run vs ±1.15% in another demonstrates this.
- **Suggested fix:** Run benchmarks with `php -n -d opcache.enable_cli=1`
  or disable xdebug for benchmark runs.

## Candidate helpers entries

### DEC-XXX: Single-pass upload processing (validate-while-converting)
- **Tags:** performance, architecture, http
- **Trigger:** "changing file upload validation or processFiles in RequestConverter"
- **Body:** `RequestConverter::processFiles()` performs shape recognition
  and required-field validation in a single traversal, using
  `FileUploadValidator`'s predicates and error-message helpers as the
  single source of truth. Do not reintroduce a separate `validate()` pass
  before conversion. The `$files !== []` fast path must be preserved.
  `FileUploadValidator::validate()` is kept as a standalone API but is no
  longer called from the request conversion hot path.

### FAQ-XXX: Shape recognition must match between validator and converter
- **Tags:** tests, http
- **Trigger:** "adding a new upload shape or changing isSingleFileEntry/isFileList"
- **Body:** When collapsing `validate()` + `processFiles()` into one pass,
  the converter must produce the exact same errors as the validator for
  every malformed shape. The hardest case is `['field' => ['subfield' =>
  ['not an array']]]` — the validator throws "unrecognized structure" for
  `field[subfield]` (because `['not an array']` is an array but neither a
  file list nor a single file entry), but a naive converter would recurse
  and throw "expected array, got string". The converter's nested-container
  branch must mirror `validateNestedAssociative` exactly.
