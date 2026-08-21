# Code Decision #1 — Issue #671: SfxDownloader partial extraction cleanup

## Approach taken

Track successfully extracted entries in `extractToDirectory()` and remove them
on mid-extraction failure; also remove all extracted entries when
`locateSfxEntry()` fails to find a usable SFX entry.

### Changes to `src/Phar/SfxDownloader.php`

1. **`extractToDirectory()`** — now returns `string[]` (the list of extracted
   entry names, in extraction order) instead of `void`. The extraction loop is
   wrapped in a try/catch: on `\RuntimeException` (from validation, containment
   check, or `extractTo()` failure), `removeExtractedEntries()` is called with
   the entries extracted so far before rethrowing.

2. **`extractZip()`** — the `extractToDirectory()` return value is captured in
   `$extractedEntries`. A second try/catch wraps the `locateSfxEntry()` call:
   on `SfxExtractionException`, all extracted entries are removed before
   rethrowing.

3. **`removeExtractedEntries()`** (new private method) — iterates extracted
   entry names in **reverse** extraction order, removing each file (`unlink`)
   or directory (`rmdir`, only if empty). Reverse order ensures nested
   directories are removed before their parents (e.g. `sub/nested/` before
   `sub/`). Silently skips entries no longer on disk. Never follows symlinks
   (`is_link` is checked before `is_dir`).

### Changes to `tests/Phar/SfxDownloaderTest.php`

Two new tests:

- `testExtractZipRemovesPreviouslyExtractedEntriesOnMidExtractionFailure` —
  creates a zip with a valid first entry (`goodfile.bin`) followed by an entry
  that escapes via a pre-existing symlink (`sub/evil.bin`). Asserts that
  `goodfile.bin` is removed after the failure.

- `testExtractZipRemovesExtractedEntriesWhenNoSfxEntryFound` — creates a zip
  with only empty directory entries (`subdir/`, `subdir/nested/`). Asserts
  that the extracted `subdir/` directory tree is removed after
  `SfxExtractionException`.

### Changes to `CHANGELOG.md`

Added a "Fixed" entry under `[Unreleased]`.

## What I rejected and why

### Staging directory approach

The issue suggested an alternative: extract into a fresh staging directory per
fetch and move the final result into place. I rejected this because:

- It changes the directory layout (adds a staging dir that must be cleaned up
  even on success).
- It's more complex: create temp dir, extract, locate SFX, move file, remove
  staging dir — more failure points.
- The tracking approach is simpler and fits the existing one-entry-at-a-time
  extraction loop perfectly.

### Cleanup in `fetch()` catch block

I initially tried to put the cleanup in `extractZip()`'s catch block by
capturing `$extractedEntries` from `extractToDirectory()`'s return value. This
**did not work**: if `extractToDirectory()` throws, it never returns, so the
variable stays `[]` — the catch block has no entries to clean up. The tracking
must happen *inside* `extractToDirectory()` where the partial list is visible.

### Catching `\Throwable` in `extractToDirectory()`

I only catch `\RuntimeException` because that's the only exception type the
validation/extraction code throws. Catching `\Throwable` would be overly broad
and could mask unexpected errors.

## Anything I was unsure about

- **`@unlink`/`@rmdir` suppression**: I used the `@` operator to suppress
  errors during cleanup. The rationale is that cleanup is best-effort — if a
  file can't be removed (permissions, race), the primary exception should still
  propagate. The operator who needs to know can inspect the destination
  directory. An alternative would be to `error_log()` cleanup failures (like
  the archive unlink in `fetch()`), but that seemed like over-engineering for a
  rollback path.

- **Empty directory materialization**: `ZipArchive::extractTo()` with an
  empty-dir entry (`addEmptyDir('subdir')`) does create the directory on disk
  on macOS/PHP 8.5. On other platforms it might not, so `removeExtractedEntries`
  silently skips missing entries. The test uses `@requires extension zip` but
  doesn't skip on any specific platform.
