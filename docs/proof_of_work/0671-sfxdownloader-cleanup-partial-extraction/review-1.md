# Review Round 1 — Issue #671: SfxDownloader partial extraction cleanup

## Scope

Files in the diff:
- `src/Phar/SfxDownloader.php` — `extractZip()`, `extractToDirectory()`, new `removeExtractedEntries()` method
- `tests/Phar/SfxDownloaderTest.php` — two new test methods
- `CHANGELOG.md` — "Fixed" entry under `[Unreleased]`

## Knowledge-base consultation

Read via tag index — tags `sfx`, `download`, `checksum` matched:

- **FAQ-003** (`sfx`, `download`, `checksum`): "A failed-checksum or unusable
  artifact must be unlinked, or every later build fails the same way." The
  principle: "never leave bytes behind that a later run will trust." This
  change extends that principle from the archive file to partially extracted
  zip entries — a direct continuation of FAQ-003's scope. **No violation.**
  The candidate KB entry proposed by the coder (findings-coder.md point #5)
  would be better as an addendum to FAQ-003 than a standalone entry, since
  it is the same principle applied to a new phase.

No decisions.md entries matched the tags `sfx`, `download`, `checksum`.

## Prior review findings

No `findings-review.md` existed prior to this round — this is the first
review. The coder's `findings-coder.md` raised five self-reported items;
their disposition is noted inline in the findings below where relevant.

## Automated checks run

| Check | Result |
|-------|--------|
| PHPUnit `SfxDownloaderTest` (59 tests) | ✅ all pass |
| PHPStan level 8 (`src/Phar/SfxDownloader.php`, `tests/Phar/SfxDownloaderTest.php`) | ✅ no errors |
| php-cs-fixer (full project, `--dry-run`) | ✅ 0 violations |
| `git diff master...HEAD` | reviewed in full |

## Architecture analysis

### Exception flow

`SfxExtractionException extends \RuntimeException` (confirmed in
`src/Exception/SfxExtractionException.php`). The exception flow is:

1. **`extractToDirectory()`** — `try { extraction loop } catch (\RuntimeException $e)`
   catches exceptions from `validateEntryName()`, `assertEntryContainedIn()`,
   and `extractTo()` returning `false`. None of these throw
   `SfxExtractionException`. The catch calls `removeExtractedEntries()` then
   rethrows. **Correct.**

2. **`extractZip()` first try/finally** — wraps `listEntryNames()` and
   `extractToDirectory()`. The `finally` only closes the zip. No catch block,
   so exceptions propagate to `fetch()`. Neither called method throws
   `SfxExtractionException`. **Correct.**

3. **`extractZip()` second try/catch** — wraps `locateSfxEntry()` which is
   the only method that throws `SfxExtractionException`. The catch removes
   extracted entries and rethrows. **Correct.**

4. **`fetch()` catch `\RuntimeException`** — catches both `\RuntimeException`
   and `SfxExtractionException` (since the latter extends the former), unlinks
   the zip archive, and rethrows. Exception type and message are preserved.
   **Correct.**

### The `SfxExtractionException` vs `\RuntimeException` catch ordering

The coder flagged this as fragile (findings-coder.md point #2). The concern:
if someone later adds a `catch (\RuntimeException $e)` to the first try block
in `extractZip()`, it would accidentally catch `SfxExtractionException` too.
This is technically true but practically safe because:
- `SfxExtractionException` is only thrown by `locateSfxEntry()`.
- `locateSfxEntry()` is called in the second try block, not the first.
- The first try block's `finally`-only design makes accidental catches unlikely.
- A comment documenting the intentional separation would help, but is not
  required for correctness.

### `removeExtractedEntries()` reverse-order directory removal

The method iterates `array_reverse($extractedEntries)` so that nested
directories (e.g. `subdir/nested/`) are removed before their parents
(`subdir/`). This works correctly when zip entries are in natural order
(directory entries before their contents). See F-2 for an edge case.

### `removeExtractedEntries()` symlink safety

The method checks `!is_link($path)` before `is_dir($path)`, preventing
`rmdir()` from being called on a symlink-to-directory (which would remove
the target directory, not the link). Symlinks are unlinked via the
`is_link($path)` branch. **Correct.**

### Path construction consistency

`removeExtractedEntries()` uses the raw `$destinationDir` (same as
`$zip->extractTo($destinationDir, ...)`), not the `realpath()`-resolved
value used in `assertEntryContainedIn()`. This is consistent: files are
written to the raw path and removed from the raw path. The `realpath()`
in `extractToDirectory()` is only for the containment check. **Correct.**

### `@` suppression in `removeExtractedEntries()`

All filesystem operations (`@rmdir`, `@unlink`) use `@` and ignore the
return value. See F-1 for the inconsistency with `fetch()`'s archive unlink.

## Findings

### F-1 — Medium — Silent cleanup failures in `removeExtractedEntries()`

**File:** `src/Phar/SfxDownloader.php`, lines 539–541

`removeExtractedEntries()` uses `@rmdir()` and `@unlink()` but never checks
the return value or calls `error_log()`. In contrast, `fetch()`'s archive
unlink (lines 94–101) checks the return value and `error_log()`s a warning
when it fails. If a partially extracted file cannot be removed (permissions,
locked file, race condition), the operator has no way to learn about it.
The leftover file could then be trusted by a later `fetch()` call that
short-circuits on `is_file($destination)` — the same class of bug that #642
fixed for the archive itself (FAQ-003).

The coder acknowledged this in findings-coder.md point #4 and assessed the
risk as lower than the archive case because `fetch()` short-circuits on the
destination filename (derived from the URL), not the extracted entry name.
That assessment is correct for the common case, but the risk is not zero:
if the SFX entry's name matches what a later `fetch()` expects, a leftover
partial file could be trusted. At minimum, `error_log()` the failure so the
operator can investigate.

**Automated check that could catch this:** a test that simulates a cleanup
failure (e.g. `chmod` the destination directory to read-only after extraction)
and asserts that `error_log()` contains a warning — analogous to
`testExtractZipLogsWarningWhenFailedArchiveCannotBeRemoved`.

### F-2 — Low — Orphaned parent directories with file-before-dir entry order

**File:** `src/Phar/SfxDownloader.php`, lines 535–543

When zip entries are ordered with a file before its parent directory entry
(e.g. `["subdir/file.txt", "subdir/"]`), the reverse-order cleanup tries to
`rmdir("subdir")` before `unlink("subdir/file.txt")`. The `rmdir` silently
fails (directory not empty), then the file is removed, leaving `subdir`
orphaned on disk. The same issue occurs for auto-created parent directories
that have no corresponding entry in the zip (e.g. extracting `subdir/file.txt`
when `subdir/` is not a zip entry — `subdir` is auto-created by `extractTo()`
but never tracked in `$extractedEntries`).

This is a limitation of the tracking approach, not a regression (the previous
code left ALL extracted entries on disk). The most common zip layouts have
directory entries before their contents, so this edge case is unlikely in
practice with phpmicro.sfx archives.

**Automated check that could catch this:** a test with entries in
file-before-dir order, asserting that all directories (including auto-created
parents) are removed after cleanup.

### F-3 — Low — Misleading test comment in `testExtractZipRemovesExtractedEntriesWhenNoSfxEntryFound`

**File:** `tests/Phar/SfxDownloaderTest.php`, lines 591–599

The comment says "Create an archive with a subdirectory file (so extraction
does materialise real content on disk) plus an empty directory" but the test
only calls `addEmptyDir('subdir')` and `addEmptyDir('subdir/nested')` — no
files at all. The comment also includes a confusing chain of reasoning
("Actually, the fallback picks ANY file entry — so to trigger the
SfxExtractionException we need an archive with only directory entries") that
should be simplified. The test itself is correct; only the comment is
misleading.

**Automated check:** none — this is a documentation issue in a test comment.

### F-4 — Nit — No test for `extractTo()` returning `false` as a mid-extraction failure

**File:** `tests/Phar/SfxDownloaderTest.php`

The code handles `$zip->extractTo()` returning `false` (lines 497–503) as a
mid-extraction failure, but no test exercises this specific path. The
mid-extraction test uses a symlink escape (which fails at
`assertEntryContainedIn()` before `extractTo()` is called). Testing
`extractTo()` failure is difficult to trigger reliably since it rarely fails
in practice, so this is a nit.

**Automated check:** a test with a mock or a corrupted entry that causes
`extractTo()` to return `false` — but this is hard to construct reliably.

### F-5 — Nit — No test for directory cleanup on mid-extraction failure

**File:** `tests/Phar/SfxDownloaderTest.php`

`testExtractZipRemovesPreviouslyExtractedEntriesOnMidExtractionFailure` only
verifies that a file (`goodfile.bin`) is cleaned up on mid-extraction failure.
It does not test that directories extracted before the failure are also
cleaned up. The no-sfx-entry test does test directory cleanup, but only on
the `SfxExtractionException` path, not the mid-extraction `\RuntimeException`
path. A test with a directory entry followed by a failing entry would close
this gap.

**Automated check:** a test with entries `["subdir/", "subdir/goodfile.bin",
"sub/evil.bin"]` (where `sub` is a symlink), asserting `subdir/` and
`subdir/goodfile.bin` are both removed.

## Candidate KB entry

The coder proposed (findings-coder.md point #5):

> **Title:** A failed mid-extraction must clean up partially extracted entries
> **Tags:** `sfx`, `download`, `checksum`
> **Trigger:** editing `SfxDownloader::extractToDirectory()` or `extractZip()`

**Recommendation:** fold into FAQ-003 as an addendum rather than creating a
new entry. The principle is the same ("never leave bytes behind that a later
run will trust"), just extended to the extraction phase. A new entry would
duplicate the FAQ-003 rationale. The retro step should decide.

## Summary

The implementation is sound. The exception handling is correct on all failure
paths: mid-extraction `\RuntimeException` is caught and cleaned up inside
`extractToDirectory()`, and `SfxExtractionException` from `locateSfxEntry()`
is caught and cleaned up in `extractZip()`. The `SfxExtractionException`
extends `\RuntimeException` but is never thrown inside `extractToDirectory()`,
so the broader catch there cannot accidentally swallow it. The reverse-order
directory removal is correct for the common case. The symlink safety check
(`!is_link` before `is_dir`) is correct. All tests pass, PHPStan level 8 is
clean, and php-cs-fixer is clean.

The one finding worth addressing before merge is F-1 (silent cleanup failures):
the inconsistency with `fetch()`'s error-logging pattern means an operator
could be unaware of leftover partial files. F-2 is a known limitation of the
tracking approach that could be documented. F-3–F-5 are nits.
