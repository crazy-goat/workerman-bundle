# Code review — round 1 — #703 `writeStream()` finally-block unlink hardening

## Scope & method
Reviewed `git diff origin/master...HEAD` (commit `6031f1e`):
- `src/Phar/SfxDownloader.php` — `writeStream()` finally block (lines 327-340).
- `tests/Phar/SfxDownloaderTest.php` — new test `testWriteStreamLogsWarningWhenPartialArtifactCannotBeRemoved` (~1086) and `FailingUnlinkStreamWrapper` (~1239-1371).
- `docs/proof_of_work/0703-.../code-decision-1.md`, `findings-coder.md`.

Read `docs/helpers/faq.md` / `decisions.md` tag index first; matching tags for
this diff are `sfx`/`download`/`checksum` (FAQ-003), `logging` (DEC-017),
`tests`/`phpstan` (FAQ-014). The change is consistent with these: DEC-017
mandates `error_log()` over `trigger_error(E_USER_WARNING)` for advisory
no-logger warnings, and FAQ-003 documents the "never leave bytes a later
`fetch()` will trust" class — exactly what this change and the existing
checksum/zip cleanup paths follow.

## The change itself (correct)
`writeStream()`'s `finally` now does:
```php
if ($failed && is_file($destination)) {
    $removed = @unlink($destination);
    if (!$removed) {
        error_log(sprintf('Unable to remove partial SFX download ...', $destination));
    }
}
```
- `$failed` is set only in the `catch (\Throwable)` and re-thrown, so the
  original exception type/message propagate unchanged — the `error_log()`
  cannot convert the failure into a different exception (DEC-017's
  fail-open requirement is met; `error_log()` never throws).
- Success path (`$failed === false`) skips unlink entirely — no spurious
  removal/logging. Verified against `testDownloadExceedingMaxSizeIsAborted`
  which still asserts the partial file is *removed* on the success path.
- `is_file($destination)` resolves through the wrapper's `url_stat` correctly
  (the wrapper `stat()`s the re-rooted real path), so there is no
  double-removal and no missed-removal.

## Static analysis (run, read-only)
- `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php src/Phar/SfxDownloader.php tests/Phar/SfxDownloaderTest.php` → **0 files to fix** (clean).
- `vendor/bin/phpstan analyse src/Phar/SfxDownloader.php tests/Phar/SfxDownloaderTest.php` → **[OK] No errors** (level 8).
- `vendor/bin/phpunit --filter testWriteStreamLogsWarningWhenPartialArtifactCannotBeRemoved tests/Phar/SfxDownloaderTest.php` → **1 test, 4 assertions, pass** (exit 1 is only the no-coverage-driver warning + 4 incidental deprecations unrelated to this change; the runner prints "OK, but there were issues"). The test reads the captured `error.log`, asserts the warning string is present, and asserts the partial `failunlink:///wrap/big` still exists — it deterministically exercises the failing-unlink path.

## Findings
| # | file:line | severity | summary |
|---|-----------|----------|---------|
| F-1 | code-decision-1.md | low | Line-number refs wrong: finally is 327-340, checksum catch 64-83, zip catch 86-104, fopen at 278 (doc says 281 / 101-116 / 135-149). |
| F-2 | SfxDownloaderTest.php:1094-1130 | low (non-defect) | `error_log` capture is process-global; robust via `assertStringContainsString` + fresh `uniqid()` temp file. No real flaw. |
| F-3 | SfxDownloaderTest.php:1309 | nit | Wrapper `unlink()` declares no params though PHP passes path/options; works, but inconsistent with the contract. |
| F-4 | SfxDownloaderTest.php:1263-1273 | low (latent) | Wrapper registered process-globally, never unregistered; single-consumer today, harmless but worth a comment. |
| F-5 | SfxDownloader.php:327 | none | Success path correctly skips unlink/log. Verified — not a defect. |

## Out-of-scope items noted by the coder (F-2/F-3/F-4 in findings-coder.md)
- **Coder F-2** (`file_get_contents` ignores `error_log` redirect): not a real
  bug; matches the existing #670 test and is robust. Agree: leave as-is.
- **Coder F-3** (`writeStream()` opens `$out` with non-`@` `fopen` at 278):
  real, but genuinely out of scope for #703 (the issue is the finally-block
  unlink, not the open). `@`-prefixing the `fopen` for parity with the
  `@fopen` at line 161 is a reasonable follow-up in a separate PR. Confirmed
  the doc's claim that line 281 is the open is *off by 3* (it is 278).
- **Coder F-4** (destination-dir writable pre-check): real hardening
  opportunity, but unrelated to #703 and correctly split out.

## Verdict
**No high or medium findings. The change is correct, type-safe, and
PSR-12-clean; the regression test genuinely exercises the failure path and
asserts the partial artifact remains.** Safe to merge after optionally
fixing the doc line-number references (F-1). F-3/F-4 are nits/latent and may
be addressed at the coder's discretion; the out-of-scope F-2/F-3/F-4 from the
coder's notes are correctly deferred.

## Proposed `docs/helpers/faq.md` candidate (for the retro step only — not committed)
Append to FAQ-003 ("SFX downloads") body:
> `writeStream()`'s finally-block unlink was hardened in #703 to match the
> checksum/zip cleanup pattern — if the removal fails it is `error_log()`d
> rather than silently left behind.
