# Code Decision 1 — Issue #566: Single-pass upload processing

## Approach taken

**Validate-while-converting** (the issue's recommended shape): collapsed the
two-pass `FileUploadValidator::validate()` + `RequestConverter::processFiles()`
into a single traversal in `processFiles()`.

### What changed

**`src/Validator/FileUploadValidator.php`**
- Extracted three new public helpers so `RequestConverter` can raise errors
  with the exact same text without duplicating message construction:
  - `assertRequiredFields(string $fieldName, array $file)` — the
    required-field check (was `validateSingleFileArray`, private).
  - `expectedArrayError(string $fieldName, mixed $value)` — returns the
    "expected array, got X" exception (was inlined in 3 places).
  - `unrecognizedStructureError(string $fieldName, array $data)` — returns
    the "unrecognized structure" exception (was inlined in 1 place).
- `validate()` is kept as a standalone entry point (existing
  `FileUploadValidatorTest` calls it directly). Its internals were refactored
  to call the new helpers so there is one source of truth for each message.
- The validator is NOT coupled to `UploadedFile` or Symfony classes.

**`src/DTO/RequestConverter.php`**
- Removed the `FileUploadValidator::validate($files)` call from
  `toSymfonyRequest()`. The `$files !== []` fast path is preserved — only
  `processFiles()` runs when files are present.
- Rewrote `processFiles()` to delegate to `processFileNode()` which performs
  full shape recognition (`isFileList` → `isSingleFileEntry` → nested
  associative container) and inline validation in one pass.
- `processFileEntry()` calls `FileUploadValidator::assertRequiredFields()`
  before constructing the `UploadedFile`.
- Non-array entries inside file lists are caught with
  `FileUploadValidator::expectedArrayError()` before reaching
  `UploadedFile`'s constructor (which would throw a TypeError).

## What I rejected and why

1. **Convert-during-validation** (validator returns the converted structure):
   rejected per the issue — couples the validator to `UploadedFile` and
   `symfony/http-foundation`, making it harder to test in isolation.

2. **Having `processFiles` call `validate()` internally then convert**: that
   would still be two traversals — just moved inside one method. The issue
   asks for a single traversal.

3. **Making `processFileNode` recurse arbitrarily deep for nested containers**:
   the validator only supports one level of nested associative (it throws
   "unrecognized structure" for deeper nesting). I matched this exactly —
   the nested-container branch checks each child with `isFileList` /
   `isSingleFileEntry` and throws "unrecognized structure" if neither
   matches, instead of recursing further. This preserves the exact same
   validation boundary.

4. **Removing `validate()` entirely**: rejected because
   `FileUploadValidatorTest` calls it directly and the issue says to keep
   existing test cases green. It's also a reasonable standalone API.

## Uncertainties

- The benchmark (`benchMultipartRequest`) shows no meaningful throughput
  improvement (5.53μs before → 5.67μs after, within noise with xdebug on).
  The benchmark uses a single-file multipart request, so the second
  traversal was trivially cheap. The issue itself notes this is "a
  code-structure problem more than a throughput problem." A benchmark with
  many nested files would show a larger delta, but the existing bench
  subject is single-file only.
- The `processFileNode` method duplicates the `isFileList` iteration
  pattern (once at the top level, once in the nested-container branch).
  I considered extracting a `processFileList()` helper but decided the
  two-site duplication is minimal and clearer inline.
