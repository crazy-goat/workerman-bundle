# Review — round 1 (issue #670, branch fix/issue-670-sfxdownloader-zip-extraction-cleanup-doe)

**HEAD:** 86da30a
**Diff:** `src/Phar/SfxDownloader.php` (zip-extraction catch ~line 92), `tests/Phar/SfxDownloaderTest.php` (new test + tightened assertion)

## Earlier findings

`findings-review.md` does not exist — this is round 1. No earlier review findings to revisit.

The coder's own `findings-coder.md` flagged `writeStream()` line 322 as a pre-existing gap in the same class; that observation is valid and carried forward as finding F-1 below.

## Verdict

**Approve with one out-of-scope note.** The change correctly mirrors the checksum path's `@unlink()` + return-check pattern in the zip-extraction catch, uses `error_log()` as the right no-logger signal channel (not `trigger_error()`, which a strict error handler could convert to an `ErrorException` and hijack the rethrow), preserves the rethrown exception byte-identically (`throw $e;`), and adds a real integration test that exercises the unlink-failure path. The tightened `assertSame` on the corrupt-archive message is a cheap and correct regression guard.

## New findings

### F-1 | src/Phar/SfxDownloader.php:322 | `writeStream()` finally block still has a bare `unlink()` — same class as #670, pre-existing | medium

The partial-artifact cleanup in `writeStream()`'s `finally` block does `unlink($destination)` with no `@`, no return-value check, and no `error_log()` warning on failure. If that `unlink()` fails (read-only mount, ownership change, SELinux), a **partial** download stays on disk and the next `fetch()` sees `is_file($destination)` as true, treats it as a complete download, and either fails verification (with checksum) or tries to extract/use a truncated file (without checksum). This is the same self-perpetuating-poison class that #670 fixes for the zip-extraction path, and it is arguably worse because the leftover bytes are a *partial* download, not a fully-downloaded-but-corrupt archive.

The coder noted this in `findings-coder.md` but it was intentionally left out of scope for this issue. It is pre-existing (not introduced by this diff) and does not block the current change, but it should be tracked for a follow-up.

### F-2 | tests/Phar/SfxDownloaderTest.php:421 | `error_log` capture may include unrelated PHP warnings on some CI configs | nit

The test captures `error_log` output via `ini_set('error_log', $logFile)` and asserts `assertStringContainsString` on the removal-failure message. If `log_errors` is `On` in a CI php.ini and any unsuppressed `E_WARNING` fires during the test (none currently do — `@unlink` suppresses its own warning, and `ZipArchive::open()` returns an error code rather than warning), the log file could accumulate extra lines. The `assertStringContainsString` check is robust against this (it only checks for the substring), so this is not a correctness issue — just a note that the test relies on the assertion being substring-based, not equality-based.

## What I checked and found clean

- **Rethrow contract:** `throw $e;` rethrows the original instance — type (`SfxExtractionException` extends `\RuntimeException`) and message are preserved exactly. The test asserts `assertSame(sprintf('Failed to open zip archive "%s".', $zipPath), $e->getMessage())`. Verified by running the test (passes).
- **FAQ-003 compliance:** FAQ-003 says "zip-extraction failures rethrow the original exception (type and message preserved) after unlinking" and "Only the checksum path appends an explicit removal note." The change adds an `error_log()` warning on the zip path (not a message note), so the exception message stays unchanged — consistent with FAQ-003. No violation.
- **`error_log()` vs `trigger_error()`:** The coder's `code-decision-1.md` correctly identifies that `trigger_error(E_USER_WARNING)` would invoke the PHP error handler, which under Symfony's `DebugErrorHandler` (debug mode) converts warnings to `ErrorException`, replacing the rethrown `\RuntimeException`. `error_log()` writes directly to the log and cannot throw. This matches the codebase convention (`ServerWorker.php`, `HttpRequestHandler.php`, `RequestConverter.php` all use `error_log()` for no-logger paths). Sound decision.
- **`@unlink()` + E_WARNING tradeoff:** The `@` suppresses the `E_WARNING` that PHP emits on unlink failure; the return-value check detects the failure instead. This is identical to the checksum path (line 72) and avoids noisy double-reporting (E_WARNING + explicit error_log). Correct.
- **PSR-12 compliance:** No violations. The `@` operator usage, `sprintf()` formatting, and control structure spacing are all consistent with the surrounding code and PSR-12.
- **Issue body claim (SfxDownloader.php:72-84 is the correct pattern):** Verified — the checksum path at lines 64–78 does `@unlink()` + return check + conditional message note. The zip path at lines 92–103 now mirrors the `@unlink()` + return check portion. The intentional difference (checksum appends to message, zip uses error_log) is documented in FAQ-003 and the coder's decision doc.
- **Test skip guard:** `chmod($dir, 0555)` + `is_writable($dir)` skip correctly handles root (where mode bits are ignored) and mode-bit-ignoring filesystems. Verified on this machine (euid=501, `is_writable` returns false on 0555 dir — test runs, not skipped).
- **Test cleanup:** The `finally` block restores `chmod($dir, 0755)` and `ini_restore('error_log')` before the outer assertions, so `tearDown`'s `rmdirRecursive` can clean up the readonly directory. No resource leak.
- **Test correctness:** The corrupt zip is pre-placed at `$destination`, so `fetch()` sees `is_file=true`, skips download, goes straight to `extractZip()` → `openArchive()` fails → catch → `@unlink()` fails (dir 0555) → `error_log()` → `throw $e`. The test asserts the exact message, the error_log content, and that the file still exists. All three assertions are meaningful. Both tests pass (`vendor/bin/phpunit --filter ...`, 2 tests, 6 assertions, OK).
- **No staged files, no source/test/config modifications** by this review.

## Candidate knowledge-base entries

**none** — FAQ-003 already covers the "never leave bytes behind" principle and the checksum/zip path behavioral difference. The `writeStream()` gap (F-1) is the same FAQ-003 class; a retro entry could reference it, but no new tag or entry is warranted from this review alone.
