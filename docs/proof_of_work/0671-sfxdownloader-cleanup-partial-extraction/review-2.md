# Review Round 2 — Issue #671: SfxDownloader partial extraction cleanup

## Scope

Files in the diff (unchanged from round 1):
- `src/Phar/SfxDownloader.php` — `extractZip()`, `extractToDirectory()`, new `removeExtractedEntries()` method
- `tests/Phar/SfxDownloaderTest.php` — three new test methods
- `CHANGELOG.md` — "Fixed" entry under [Unreleased]

## Knowledge-base consultation

Read via tag index — tags `sfx`, `download`, `checksum` matched:

- **FAQ-003** (`sfx`, `download`, `checksum`): "A failed-checksum or
  unusable artifact must be unlinked, or every later build fails the same
  way." The principle: "never leave bytes behind that a later run will
  trust." This change extends that principle from the archive file to
  partially extracted zip entries. **No violation.** The F-1 fix (adding
  `error_log()` on cleanup failures) makes the implementation more
  consistent with FAQ-003's principle. The F-2 fix (auto-created parent
  directory collection) extends the cleanup scope but introduces a new
  ordering bug (see F-6 below).

No decisions.md entries matched the tags `sfx`, `download`, `checksum`.

## Prior review findings — disposition

| ID | Severity | Round 1 status | Round 2 disposition | Evidence |
|----|----------|----------------|---------------------|----------|
| F-1 | medium | fixed | **fixed — verified correct** | `removeExtractedEntries()` now checks return values of `@unlink`/`@rmdir` and `error_log()`s warnings on failure (lines 567–594). The pattern is consistent with `fetch()`'s archive-unlink logging (lines 94–101). Three separate branches (symlink, dir, file) each log with a descriptive message. The dir branch additionally suppresses logging for non-empty dirs (expected when children haven't been removed yet) by checking `scandir` — a reasonable refinement. |
| F-2 | low | fixed | **fixed but incomplete — see F-6 (new)** | Auto-created parent directories are now collected (lines 543–553) and included in the cleanup. However, the ordering of parents in the reverse-merge is wrong for multi-level nesting: `array_reverse(array_keys($parents))` reverses insertion order (deepest-first), producing shallowest-first — the opposite of what `rmdir` needs. This orphans intermediate parent directories. See F-6 for details. |
| F-3 | low | fixed | **fixed — verified correct** | The test comment (lines 591–596) now accurately describes the test: "Create an archive containing only empty directory entries. Since there are no file entries, `locateSfxEntry()` cannot find a usable SFX entry and throws `SfxExtractionException`. The nested directories verify that cleanup removes both parent and child directories created during extraction." |
| F-4 | nit | not fixed | **not fixed — accepted** | `extractTo()` returning `false` remains untested. This is a reasonable acceptance — the path is extremely rare in practice and cannot be reliably triggered without mocking `ZipArchive`, which the test suite does not do. The mid-extraction failure path is exercised by the symlink-escape test (which fails at `assertEntryContainedIn()` before `extractTo()`). No change needed. |
| F-5 | nit | fixed | **fixed — verified correct** | `testExtractZipRemovesDirectoriesOnMidExtractionFailure` (lines 629–669) tests directory + auto-created parent cleanup on the `\RuntimeException` path. The test uses `gooddir/goodfile.bin` (single-level nesting) followed by a failing entry. It asserts that `gooddir/goodfile.bin`, `standalone.bin`, and the `gooddir/` parent are all removed. **However**, this test only covers single-level nesting — the multi-level nesting bug (F-6) is not caught. |

## Automated checks run

| Check | Result |
|-------|--------|
| `vendor/bin/phpunit tests/Phar/SfxDownloaderTest.php` | ✅ 56 tests, 146 assertions, all pass |
| `composer lint` (php-cs-fixer + phpstan + rector + kb-lint + check-changelog) | ✅ all clean |
| Manual simulation of multi-level parent cleanup | ❌ confirmed F-6 bug |
| End-to-end test via `fetch()` with multi-level nesting | ❌ confirmed F-6 bug |

## New findings

### F-6 — Medium — Parent directory cleanup ordering is wrong for multi-level nesting

**File:** `src/Phar/SfxDownloader.php`, lines 555–561

The F-2 fix added auto-created parent directory collection (lines 543–553)
and included them in the cleanup via `array_merge(array_reverse($extractedEntries), array_reverse(array_keys($parents)))`.
The comment says "also reverse-sorted so deeper parents are removed before
shallower ones," but `array_reverse(array_keys($parents))` does **not** sort
by depth — it merely reverses the insertion order.

The parent collection while-loop (lines 544–553) walks **up** from the
immediate parent toward the root, inserting parents deepest-first. For an
entry like `a/b/c/d.txt`, the insertion order is `['a/b/c', 'a/b', 'a']`.
After `array_reverse`, this becomes `['a', 'a/b', 'a/b/c']` — shallowest
first. `rmdir` requires deepest-first (remove `a/b/c` before `a/b`
before `a`), so this ordering causes:

1. `rmdir('a')` → fails (non-empty: has `a/b`), silently skipped
2. `rmdir('a/b')` → fails (non-empty: has `a/b/c`), silently skipped
3. `rmdir('a/b/c')` → succeeds (empty after `d.txt` was unlinked)

**Result:** `a/` and `a/b/` are orphaned on disk with no warning. The
`rmdir` failures are silently suppressed because the directories are
non-empty (the `scandir` check on line 581 sees more than 2 entries).

**Confirmed with a real end-to-end test:** a zip containing
`a/b/c/d.txt` (no dir entries) followed by `sub/evil.bin` (escaping via
symlink) triggers a mid-extraction `RuntimeException`. After cleanup:
- `a/b/c/d.txt` → removed ✅
- `a/b/c/` → removed ✅
- `a/b/` → **orphaned** ❌
- `a/` → **orphaned** ❌

**Why the existing tests don't catch this:** The F-5 test
(`testExtractZipRemovesDirectoriesOnMidExtractionFailure`) uses only
single-level nesting (`gooddir/goodfile.bin`), where the parent array has
a single entry (`['gooddir']`) and `array_reverse` is a no-op. The
no-sfx-entry test uses empty dir entries (`subdir/`, `subdir/nested/`),
which are tracked in `$extractedEntries` and processed first in reverse
order — the parent entries are redundant and the ordering bug is masked.

**Fix:** sort parents by path depth (deepest first) instead of reversing
insertion order. For example:

```php
$parentKeys = array_keys($parents);
usort($parentKeys, static fn (string $a, string $b): int =>
    substr_count($b, '/') <=> substr_count($a, '/')
);
$allPaths = array_merge(array_reverse($extractedEntries), $parentKeys);
```

Or simply sort all paths (entries + parents) by depth descending, which
handles both correctly.

**Automated check that could catch this:** a test with a multi-level nested
file entry (`a/b/c/d.txt`, no dir entries in the zip) followed by a
failing entry, asserting that `a/`, `a/b/`, and `a/b/c/` are all
removed after cleanup.

### F-7 — Low — No test for `error_log()` warnings in `removeExtractedEntries()`

**File:** `tests/Phar/SfxDownloaderTest.php`

The F-1 fix added `error_log()` warnings for file, symlink, and directory
removal failures in `removeExtractedEntries()` (lines 567–594). However,
no test verifies these warnings are actually emitted. The existing
`testExtractZipLogsWarningWhenFailedArchiveCannotBeRemoved` (lines 404–453)
tests the archive-unlink warning in `fetch()`, not the new warnings in
`removeExtractedEntries()`.

This is a low-severity gap because the `error_log()` calls are simple
and unlikely to regress, but the F-1 finding explicitly recommended a test
"analogous to `testExtractZipLogsWarningWhenFailedArchiveCannotBeRemoved`"
and that test was not added.

**Automated check that could catch this:** a test that `chmod`s the
destination directory to read-only after extraction but before the
mid-extraction failure, and asserts `error_log()` contains the expected
warning. This is difficult to construct reliably (the failure must happen
*after* at least one file is extracted but *before* the cleanup runs, and
the directory must be unwritable for `unlink` but not for the extraction
itself), so this remains a low-priority gap.

## Architecture analysis (unchanged from round 1, verified)

The exception flow is correct on all failure paths:
1. `extractToDirectory()` catches `\RuntimeException` from
   `validateEntryName()`, `assertEntryContainedIn()`, and `extractTo()`
   — cleans up and rethrows. ✅
2. `extractZip()` first try/finally only closes the zip. ✅
3. `extractZip()` second try/catch catches `SfxExtractionException` from
   `locateSfxEntry()` — cleans up and rethrows. ✅
4. `fetch()` catches `\RuntimeException` (including
   `SfxExtractionException`) — unlinks archive and rethrows. ✅

The `is_link` before `is_dir` check in `removeExtractedEntries()` is
correct — `is_dir` follows symlinks, so a symlink-to-directory would
match `is_dir` without the `is_link` guard. ✅

The `error_log()` logging pattern in `removeExtractedEntries()` is
consistent with the archive-unlink pattern in `fetch()` (lines 94–101).
Both check the return value and log on failure. The directory branch's
additional `scandir` check to suppress logging on non-empty dirs is a
reasonable refinement. ✅

## Summary

The F-1, F-3, and F-5 fixes are correct and complete. The F-2 fix is
correct in intent (collecting auto-created parent directories) but the
implementation has a parent-ordering bug for multi-level nesting (F-6).
The F-4 acceptance is reasonable. The new `error_log()` warnings from F-1
are not tested (F-7, low).

**F-6 should be fixed before merge:** it leaves orphaned directories on
disk after a failed fetch with multi-level nested file entries — the same
class of bug that issue #671 was created to fix. The fix is a one-line
sort change.

## Candidate KB entry

No new candidate entry needed — the F-6 bug is an implementation defect
in the F-2 fix, not a new recurring pitfall. The round 1 recommendation
to fold the coder's candidate entry into FAQ-003 as an addendum still
applies.
