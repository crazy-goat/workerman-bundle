# Findings (coder) — #703

Bugs, obstacles, and weak spots noticed while working #703. In-scope items
first, then out-of-scope ones the review trail should pick up.

## In scope

### F-1 (fixed by this change) — `writeStream()` finally-block unlink is bare
- **File/line:** `src/Phar/SfxDownloader.php:320` (now `315-326`)
- **Problem:** `unlink($destination)` ran without an `@`, a return-value
  check, or any failure signalling. A failed unlink left a *truncated*
  download on disk; `fetch()` then trusted it as complete.
- **Fix:** `@unlink()` + capture return + `error_log()` warning, matching
  the #670 zip-extraction pattern. Covered by the new regression test
  `testWriteStreamLogsWarningWhenPartialArtifactCannotBeRemoved`.

## Out of scope (noted for a later issue/PR)

### F-2 — `file_get_contents()` in tests ignores the `error_log` redirect
- **File/line:** `tests/Phar/SfxDownloaderTest.php:1130`
  (`@file_get_contents($logFile)`) and the equivalent in
  `testExtractZipLogsWarningWhenFailedArchiveCannotBeRemoved`.
- **Problem:** In CI the `error_log` ini is set, but `error_log()` output
  capture relies on the ini file. This works because we `ini_set()` it
  locally; it is a minor fragility, not a bug. No change made — it matches
  the existing #670 test and the issue did not ask to touch it.
- **Suggested fix:** unchanged; only flagged so the pattern stays
  consistent.

### F-3 — `writeStream()` opens `$out` with a non-`@` `fopen`
- **File/line:** `src/Phar/SfxDownloader.php:281`
  (`$out = fopen($destination, 'wb');`)
- **Problem:** on failure it throws "Unable to open ... for writing" with
  the *raw* `fopen` warning suppressed via the previous `@`? It is NOT
  `@`-prefixed here, so a failed open emits a PHP warning in addition to
  the RuntimeException. Minor noise, and the success-path behaviour was
  left intact intentionally (the issue is about the finally-block unlink,
  not the open). Not changed.
- **Suggested fix (optional, separate PR):** prefix with `@` and keep the
  existing message, for parity with the `@fopen` in
  `downloadWithRedirectCheck()` (`src/Phar/SfxDownloader.php:204`).

### F-4 — destination-dir creation in `fetch()` has no write-permission check beyond mkdir
- **File/line:** `src/Phar/SfxDownloader.php:85-87`
- **Problem:** `if (!is_dir($destinationDir) && !mkdir(...) && !is_dir(...))`
  throws only when `mkdir` fails. If the dir exists but is not writable,
  `writeStream()`'s `fopen('wb')` fails later with a generic message. Not
  related to #703; noted as a hardening opportunity.
- **Suggested fix (separate PR):** pre-validate `is_writable($destinationDir)`
  and raise a clearer `\RuntimeException`.

### F-5 — no coverage assertion that a *successful* writeStream leaves no artifact
- **File/line:** covered indirectly by `testDownloadExceedingMaxSizeIsAborted`
  (asserts the partial file is removed on the success path).
- **Observation:** the success path (unlink succeeds) is already covered;
  this change adds the failure path. Good symmetry. No action.

## Process / environment notes

- **Repo path:** the task brief said `/Users/s2x/workerman-bundle`; the
  working tree is actually `/Users/piotr.halas/work/workerman-bundle`. Used
  the real path. Branch `fix/issue-703-sfxdownloader-writestream-finally-block`
  was already checked out.
- **PHP:** 8.5.9 (newer than the composer.json floor of 8.2). `php-cs-fixer`
  warns about this but is harmless; ran it with a single file path (its
  CLI refuses multiple paths without `--config`).
- **phpstan:** passed on the changed files (after narrowing the wrapper's
  `stat()`/`fstat()` returns and `fread()` length).
- **Test run:** `vendor/bin/phpunit tests/Phar/SfxDownloaderTest.php` →
  58 tests, 156 assertions, 0 failures (1 coverage-driver warning, 4
  deprecation notices outside this change).

## Proposed `docs/helpers/faq.md` candidate entry (NOT committed — retro step only)

The issue asks for a one-line note recording that `writeStream()` is the
*third* cleanup path in `SfxDownloader` (checksum catch, zip-extraction
catch, writeStream finally). Per `docs/helpers/README.md` rule #2, I am
proposing only — not appending. Suggested edit to the existing FAQ-003
("SFX downloads") entry body, appended as one sentence:

> Same class of behavior as `writeStream()`, which unlinks the partial
> artifact on transfer abort (e6fa1b2, #585): never leave bytes behind
> that a later run will trust. **`writeStream()`'s finally-block unlink
> was hardened in #703 to match the checksum/zip cleanup pattern — if the
> removal fails it is `error_log()`d rather than silently left behind.**
