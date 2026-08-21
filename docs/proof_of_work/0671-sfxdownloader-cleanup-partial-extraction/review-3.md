# Review Round 3 — Issue #671: SfxDownloader partial extraction cleanup

## Scope

Files in the diff (unchanged from round 2):
- `src/Phar/SfxDownloader.php` — `extractZip()`, `extractToDirectory()`, new `removeExtractedEntries()` method
- `tests/Phar/SfxDownloaderTest.php` — four new test methods (one added since round 2)
- `CHANGELOG.md` — "Fixed" entry under [Unreleased]

What changed since round 2:
- F-6 fix: replaced `array_reverse(array_keys($parents))` with `usort` by path depth descending (deepest first). Added `testExtractZipRemovesMultiLevelParentsOnMidExtractionFailure` test.
- F-7: not fixed (accepted — cleanup-failure `error_log()` not reliably triggerable).

## Knowledge-base consultation

Read via tag index — tags `sfx`, `download`, `checksum` matched:

- **FAQ-003** (`sfx`, `download`, `checksum`): "A failed-checksum or unusable artifact must be unlinked, or every later build fails the same way." The principle: "never leave bytes behind that a later run will trust." This change extends that principle from the archive file to partially extracted zip entries. **No violation.** The F-6 fix (correct depth-descending sort) makes the cleanup implementation correctly fulfil FAQ-003's principle for multi-level nesting.

No decisions.md entries matched the tags `sfx`, `download`, `checksum`.

## Prior review findings — disposition

| ID | Severity | Round 2 status | Round 3 disposition | Evidence |
|----|----------|----------------|---------------------|----------|
| F-1 | medium | fixed | **fixed — verified correct** | `removeExtractedEntries()` checks return values of `@unlink`/`@rmdir` and `error_log()`s warnings on failure (lines 567–598). Three branches (symlink, dir, file) each log with a descriptive message. The dir branch suppresses logging for non-empty dirs via `scandir` check. Pattern is consistent with `fetch()`'s archive-unlink logging (lines 94–101). ✅ |
| F-2 | low | fixed but incomplete (F-6) | **fixed — verified correct** | Auto-created parent directories are collected (lines 543–553) and included in cleanup. The F-6 fix replaced the broken `array_reverse` with a correct `usort` by depth descending (lines 560–564). Multi-level nesting now works correctly. ✅ |
| F-3 | low | fixed | **fixed — verified correct** | Test comment (lines 591–596) accurately describes the test. ✅ |
| F-4 | nit | not fixed (accepted) | **not fixed — accepted** | `extractTo()` returning `false` remains untested. Reasonable acceptance — the path is extremely rare and cannot be reliably triggered without mocking `ZipArchive`. ✅ |
| F-5 | nit | fixed (single-level only) | **fixed — verified correct, now multi-level too** | `testExtractZipRemovesDirectoriesOnMidExtractionFailure` (lines 629–669) tests single-level nesting. The new `testExtractZipRemovesMultiLevelParentsOnMidExtractionFailure` (lines 680–717) tests multi-level nesting (3 levels deep). Both pass. ✅ |
| F-6 | medium | fixed | **fixed — verified correct** | `usort` by `substr_count(rtrim($path, '/'), '/')` descending correctly sorts deepest paths first. Traced through `a/b/c/d.txt`: d.txt (3) → a/b/c (2) → a/b (1) → a (0). `rmdir` requires empty dirs, so deepest-first is correct. The new multi-level test confirms end-to-end. See detailed analysis below. ✅ |
| F-7 | low | not fixed (accepted) | **not fixed — accepted** | No test for `error_log()` warnings in `removeExtractedEntries()`. Accepted: simulating a cleanup failure is not reliably triggerable in the current test flow (the failure happens inside `extractToDirectory()` before control returns to the test). The `error_log()` calls are simple and consistent with the tested `fetch()` pattern. ✅ |

## F-6 fix analysis

### Is the `usort` comparator correct?

The comparator (lines 562–563):

```php
static fn(string $a, string $b): int =>
    substr_count(rtrim($b, '/'), '/') <=> substr_count(rtrim($a, '/'), '/')
```

This sorts by the number of `/` separators in the path, **descending** (`$b` count <=> `$a` count means higher count sorts first). Depth is correctly measured by `substr_count` of `/`:

- `a/b/c/d.txt` → 3 separators → depth 3
- `a/b/c` → 2 → depth 2
- `a/b` → 1 → depth 1
- `a` → 0 → depth 0

The `rtrim($path, '/')` handles directory entries with trailing slashes (e.g. `subdir/` → `subdir` → 0 separators), so they are not over-counted.

**Verdict: correct.** The deepest paths (files and directories) are processed first, ensuring parent directories are empty before `rmdir` is attempted.

### Edge cases verified

1. **Multiple entries sharing parents** (e.g. `a/b/c.txt` + `a/b/d.txt`): parents deduplicated by hash key (`$parents[$dir] = true`). Both files at depth 2 sort before `a/b` (depth 1) and `a` (depth 0). After both files are unlinked, `a/b` is empty. ✅
2. **Entries at the same depth** (e.g. `a/b.txt` and `c/d.txt`): `usort` is not stable, so relative order is unspecified — but this is safe because they are independent subtrees. ✅
3. **File and parent at different depths** (e.g. `a/b.txt` depth 1, `a` depth 0): file sorts first, is unlinked, then `a` is rmdir'd (empty). ✅
4. **Empty `$extractedEntries`**: `$allPaths = []`, `usort` is no-op. Nothing to clean. ✅
5. **Single entry, no nesting** (e.g. `file.txt`): depth 0, single element, `usort` is no-op. ✅
6. **Backslashes in entry names**: rejected by `validateEntryName()` (line 687), so no entry contains `\`. ✅

### Is the new multi-level nesting test adequate?

`testExtractZipRemovesMultiLevelParentsOnMidExtractionFailure` (lines 680–717):

- Creates a zip with `a/b/c/d.txt` (3 levels of auto-created parents: `a`, `a/b`, `a/b/c`) followed by `sub/evil.bin` (failing entry via symlink escape).
- After failure, asserts ALL of these are removed: `a/b/c/d.txt`, `a/b/c`, `a/b`, `a`.
- This directly tests the F-6 fix: with the old `array_reverse`, `a/` and `a/b/` would be orphaned (the test would fail).

**Verdict: adequate.** The test covers the exact scenario that F-6 identified — multi-level auto-created parent directories — and asserts cleanup at every nesting level. The test passes with the fix and would fail without it.

## Automated checks run

| Check | Result |
|-------|--------|
| `vendor/bin/phpunit tests/Phar/SfxDownloaderTest.php` | ✅ 57 tests, 152 assertions, all pass |
| `composer lint` (php-cs-fixer + phpstan + rector + kb-lint + check-changelog) | ✅ all clean (kb-lint warning on faq.md line budget is pre-existing, not introduced by this diff) |
| `vendor/bin/phpstan analyse src/Phar/SfxDownloader.php --level=8` | ✅ No errors |

## New findings

### F-8 — Nit — Docblock says "reverse extraction order" but implementation now sorts by depth

**File:** `src/Phar/SfxDownloader.php`, lines 520–522

The docblock for `removeExtractedEntries()` states:

> "Entries are processed in reverse extraction order so that nested directories are removed before their parents"

The F-6 fix changed the implementation from `array_reverse($extractedEntries)` to `usort` by path depth descending, but the docblock was not updated. The inline comment (lines 555–558) was correctly updated to describe the new algorithm, but the docblock still describes the old one. Reverse extraction order and depth-descending sort are different algorithms — the former depends on entry insertion order, the latter on path structure.

**Severity: nit.** This is a documentation inaccuracy in a private method's docblock. It does not affect behaviour, but it could mislead a future reader into thinking the ordering depends on extraction order rather than path depth.

**Automated check that could catch this:** none — this is a semantic mismatch between a docblock and its implementation, which no automated tool in the repo's lint suite detects.

**Fix:** update the docblock to say "Entries are processed in depth-descending order (by path separator count) so that nested directories are removed before their parents."

## Architecture analysis (verified, unchanged from round 2)

The exception flow is correct on all failure paths:
1. `extractToDirectory()` catches `\RuntimeException` from `validateEntryName()`, `assertEntryContainedIn()`, and `extractTo()` — cleans up and rethrows. ✅
2. `extractZip()` first try/finally only closes the zip. ✅
3. `extractZip()` second try/catch catches `SfxExtractionException` from `locateSfxEntry()` — cleans up and rethrows. ✅
4. `fetch()` catches `\RuntimeException` (including `SfxExtractionException`) — unlinks archive and rethrows. ✅

The `is_link` before `is_dir` check in `removeExtractedEntries()` is correct — `is_dir` follows symlinks. ✅

The `error_log()` logging pattern is consistent with `fetch()`'s archive-unlink pattern. ✅

## Summary

The F-6 fix is correct. The `usort` by `substr_count` of `/` descending properly sorts paths deepest-first, ensuring `rmdir` is only attempted on empty directories. The new multi-level nesting test is adequate — it covers the exact scenario F-6 identified and would fail without the fix.

All 7 prior findings are resolved or accepted:
- F-1 through F-6: fixed and verified correct.
- F-4 and F-7: accepted as not fixable without unreliable test scaffolding.

One new nit (F-8): the `removeExtractedEntries()` docblock still describes the old "reverse extraction order" algorithm instead of the new depth-descending sort. This is a documentation inaccuracy in a private method's docblock — no behaviour impact, but worth fixing for accuracy.

**No blocking issues.** The implementation is sound and all automated checks pass.

## Candidate KB entry

No new candidate entry needed. The round 1 recommendation to fold the coder's candidate entry into FAQ-003 as an addendum still applies — the principle is the same ("never leave bytes behind that a later run will trust"), extended to the extraction phase. The retro step should decide.
