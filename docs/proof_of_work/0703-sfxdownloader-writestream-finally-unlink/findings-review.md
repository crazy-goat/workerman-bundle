# Findings — review round 1 (#703)

One entry per finding. `file:line` is the current (post-change) location.

## F-1 — Doc line-number references are wrong (code-decision-1.md)
- **File:** `docs/proof_of_work/0703-sfxdownloader-writestream-finally-unlink/code-decision-1.md`
- **Severity:** low (documentation only, no runtime impact)
- **Detail:** The doc states the finally block is now `src/Phar/SfxDownloader.php:315-326` and the checksum catch is at `:101-116`, the zip-extraction catch at `:135-149`, and `writeStream()` opens `$out` at `:281`. Actual locations: `finally` block = **327-340** (the `if ($failed && is_file($destination))` line is **327**, unlink at **328**); checksum catch = **lines 64-83** (verifyChecksum try at 64, catch at 65, `@unlink` at 72); zip-extraction catch = **lines 86-104** (`@unlink` at 95); `fopen($destination, 'wb')` = **line 278** (not 281). The referenced `:281` and `:101-116`/`:135-149` ranges do not point at the code the prose describes.
- **Status:** still present (fix: correct the line numbers, or drop exact line refs in favour of symbol names).

## F-2 — `error_log` capture is global and not guarded against prior/parallel writes
- **File:** `tests/Phar/SfxDownloaderTest.php:1094-1130`
- **Severity:** low (non-defect)
- **Detail:** The test does `ini_set('error_log', $logFile)` then later `file_get_contents($logFile)` and asserts the message is *contained*. Because `error_log` is process-global and other @-suppressed failures / PHP deprecations can also be written to that file (4 deprecations fire during the run), the assertion uses `assertStringContainsString` (not full-match), so it stays robust. The file is created fresh per test via `uniqid()` temp dir, so collisions are unlikely. No double-removal and the `ini_restore('error_log')` in `finally` correctly restores the prior ini value even if the assertion fails. Not a real defect, but note: if a *future* test or helper in the same process calls `error_log()` with an unrelated message containing the substring "Unable to remove partial SFX download", this test would false-pass. Acceptable risk; no change required.
- **Status:** not a real finding (robust by construction).

## F-3 — Wrapper `unlink()` is declared with no parameters; PHP invokes it with one
- **File:** `tests/Phar/SfxDownloaderTest.php:1309` (`public function unlink(): bool`)
- **Severity:** nit
- **Detail:** PHP calls the wrapper's `unlink(string $path, int $options = 0): bool`. The implementation declares `unlink(): bool` (no params). PHP silently passes the path anyway; the missing parameter does not raise a warning under default error reporting (verified: test passes). It is inconsistent with the documented signature contract and would trip PHPStan if the wrapper were production code (it is not part of `src/`, so PHPStan passes). Leaving it param-less is fine for a test helper but should carry a comment or accept `string $path, int $options = 0` for correctness. Low priority.
- **Status:** still present (nit — optional).

## F-4 — `FailingUnlinkStreamWrapper` is registered once, process-globally, and never unregistered
- **File:** `tests/Phar/SfxDownloaderTest.php:1263-1273` (`register()`)
- **Severity:** low (latent)
- **Detail:** `register()` guards with `self::$registered` so repeated calls are no-ops — good, no double-register fatal. But the wrapper stays registered for the entire PHPUnit process (no `stream_wrapper_unregister`). Any *later* test that uses a `failunlink://` path would hit the failing unlink. Currently no other test does, so dormant. The `uniqid()` temp dir plus the wrapper keying everything off `self::$baseDir` (set by the last `register()` caller) means if a second test registered the wrapper with a *different* `$baseDir`, `realPath()` would re-root under the wrong base. Only one test uses it today, harmless. Worth a one-line comment that the wrapper is single-consumer / process-global. PHPStan/php-cs-fixer clean.
- **Status:** ADDRESSED (post-round-1). Documented that the wrapper is single-consumer / process-global via a method docblock on `register()`. No behavioral change; remains dormant given only one test uses `failunlink://`.

## F-5 — Success path correctly does NOT log/remove (verified, no defect)
- **File:** `src/Phar/SfxDownloader.php:327`
- **Severity:** none
- **Detail:** The guard `if ($failed && is_file($destination))` ensures on the success path (`$failed === false`) the unlink never runs and no `error_log` is emitted. `is_file($destination)` via the wrapper's `url_stat` resolves correctly because the wrapper's `url_stat` stat()s the re-rooted real path. No double-removal, no missed-removal on the failure path (unlink attempted exactly once). Behavior matches the #670 checksum/zip pattern. No change required.
- **Status:** not a real finding (correct).

## Summary verdict
- Total findings: 5 (0 high, 0 medium, 3 low, 1 nit, 1 non-finding).
- Must-fix before merge: **none**. The only behavioral/code findings are low/nit documentation and test-hygiene items. The core change is correct, type-safe (PHPStan level 8 clean), PSR-12 clean (php-cs-fixer clean), and the regression test actually exercises the failure path (4 assertions pass, partial file asserted to still exist).
- Safe to merge after optionally correcting the doc line numbers (F-1) and, if desired, the wrapper `unlink()` signature (F-3).
