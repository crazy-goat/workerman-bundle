# Findings — Coder — Issue #671

## Changed files

1. `src/Phar/SfxDownloader.php` — `extractZip()`, `extractToDirectory()`, new
   `removeExtractedEntries()` method
2. `tests/Phar/SfxDownloaderTest.php` — two new test methods
3. `CHANGELOG.md` — "Fixed" entry under [Unreleased]

## Biggest problem faced

**The return-value-vs-exception timing bug.** My first implementation put the
cleanup in `extractZip()`'s catch block, capturing `$extractedEntries` from
`extractToDirectory()`'s return value. But when `extractToDirectory()` throws,
it never returns — so `$extractedEntries` stayed `[]` and the catch block had
nothing to clean up. The test `testExtractZipRemovesPreviouslyExtractedEntriesOnMidExtractionFailure`
caught this immediately (the first extracted file was still on disk after the
failure). The fix was to move the try/catch *inside* `extractToDirectory()`
where the partial list of already-extracted entries is visible as a local
variable. This is a subtle but important distinction: you can only clean up
what the throwing function knows about, not what the caller would have received.

**Directory removal order.** The second test
(`testExtractZipRemovesExtractedEntriesWhenNoSfxEntryFound`) failed because
`removeExtractedEntries` processed entries in forward order — trying to `rmdir`
`subdir/` before `subdir/nested/` was removed. The `rmdir` silently failed
because the directory wasn't empty. Fix: `array_reverse($extractedEntries)` so
deeper paths are removed first.

## Discovered bugs / places to improve

### 1. `listEntryNames()` validates ALL entries before ANY extraction — mid-extraction failures only come from `assertEntryContainedIn()` or `extractTo()` failures

**File:** `src/Phar/SfxDownloader.php`, lines 442-451 (`listEntryNames`) and
474-515 (`extractToDirectory`)

`listEntryNames()` runs `validateEntryName()` on every entry before
`extractToDirectory()` runs. This means `validateEntryName()` failures (path
traversal, absolute path, backslash, drive letter) abort before any file is
extracted — they can never cause a mid-extraction cleanup scenario. The only
mid-extraction failure paths are:

- `assertEntryContainedIn()` — the symlink containment backstop, which runs
  only in `extractToDirectory()` (not in `listEntryNames()`).
- `$zip->extractTo()` returning `false` — rare in practice.

This is not a bug per se, but it means the "double validation" in
`extractToDirectory()` (re-running `validateEntryName()` that already passed in
`listEntryNames()`) is redundant for the name rules — it only matters for the
containment check. **Suggested improvement:** document that
`listEntryNames()` is the all-or-nothing validation gate and
`extractToDirectory()` re-validates as a defence-in-depth measure.

### 2. `SfxExtractionException` extends `\RuntimeException` but `extractZip` catch blocks distinguish them

**File:** `src/Phar/SfxDownloader.php`, lines 391-412

The first try/catch in `extractZip()` has no catch block (only finally) —
cleanup for `extractToDirectory()` failures is handled inside
`extractToDirectory()` itself. The second try/catch catches
`SfxExtractionException` specifically. This works because `SfxExtractionException`
is only thrown by `locateSfxEntry()` which runs after the first try/finally. But
it's fragile: if someone later adds a catch for `\RuntimeException` in the first
try block, it would accidentally catch `SfxExtractionException` too (since it
extends `\RuntimeException`). **Suggested fix:** add a comment noting that
`SfxExtractionException` is intentionally not caught in the first try block
because it's only thrown by `locateSfxEntry()`.

### 3. `str_replace('.zip', '', basename($zipPath))` in `locateSfxEntry()` is too broad

**File:** `src/Phar/SfxDownloader.php`, line 605

`str_replace('.zip', '', ...)` replaces ALL occurrences of `.zip` in the
basename, not just the trailing extension. A file named `my.zip.archive.zip`
would become `my.archive` instead of `my.zip.archive`. **Suggested fix:** use
`preg_replace('/\.zip$/', '', basename($zipPath))` or check if the basename
ends with `.zip` and substring-remove only the suffix.

### 4. No `error_log()` on cleanup failure in `removeExtractedEntries()`

**File:** `src/Phar/SfxDownloader.php`, lines 532-545

Unlike the archive unlink in `fetch()` (lines 94-101) which `error_log()`s a
warning when unlink fails, `removeExtractedEntries()` silently `@`-suppresses
all errors. If a partial file can't be removed (permissions, locked file), the
operator has no way to learn about it. The partial file could then be trusted by
a later `fetch()` call that short-circuits on `is_file($destination)`. This is
the same class of bug that #642 fixed for the archive itself.
**Suggested fix:** at minimum, `error_log()` a warning when `unlink()`/`rmdir()`
fails for an extracted entry, similar to the archive-removal warning. However,
the destination path is the extracted SFX file, not the zip archive — and
`fetch()` short-circuits on `is_file($destination)` where `$destination` is
derived from the URL filename, not the extracted entry name. So a leftover
extracted file would only be found if it matches the expected filename. The risk
is lower than the archive case but still present.

### 5. Candidate KB entry

**Title:** A failed mid-extraction must clean up partially extracted entries
**Tags:** `sfx`, `download`, `checksum`
**Trigger:** editing `SfxDownloader::extractToDirectory()` or `extractZip()`
**Content:** `extractToDirectory()` extracts zip entries one at a time; if entry
N fails validation or extraction, entries 1..N-1 are already on disk. The
`fetch()` catch block (added in #642) only unlinks the zip archive, not the
extracted files. As of #671, `extractToDirectory()` tracks extracted entries and
removes them on failure (reverse order, so nested dirs are cleaned before
parents), and `extractZip()` removes all extracted entries when `locateSfxEntry()`
throws `SfxExtractionException`. Same principle as `writeStream()` and the
archive unlink: never leave bytes behind that a later run will trust.
