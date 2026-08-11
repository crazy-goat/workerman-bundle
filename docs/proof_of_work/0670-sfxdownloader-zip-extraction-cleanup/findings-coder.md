# Findings — round 1 (issue #670, coder)

## Obstacles / surprises

1. **Making `unlink()` fail deterministically in a unit test is hard.** The
   class is `final readonly`, `fetch()` calls `unlink()` directly (no seam to
   mock), and permission-based failure vanishes under root (CI containers) or
   on mode-bit-ignoring filesystems (Windows). The `is_writable($dir)` skip
   guard after `chmod 0555` is the pragmatic answer; the test runs for real
   as a non-root developer user and skips under root. Verified: the new test
   executed (not skipped) on this macOS machine. — Solved via the
   `chmod(0555)` + `is_writable()`-skip + `ini_set('error_log')` capture
   approach in `tests/Phar/SfxDownloaderTest.php`.

2. **`trigger_error()` would have broken the rethrow contract.** A strict
   error handler (Symfony debug mode) converts `E_USER_WARNING` into an
   `ErrorException`, which would be thrown instead of the original
   `\RuntimeException` at the `throw $e;` site. `error_log()` cannot throw —
   that is why it is the right channel here even though `ConfigLoader` uses
   `trigger_error` for its no-logger path. See `code-decision-1.md`.

## Bugs / weak spots noticed (in-scope and out)

- **`src/Phar/SfxDownloader.php:322` (`writeStream()` finally block)** — the
  partial-artifact cleanup also does a bare `unlink($destination)` with no
  return check and no `@`. Same class of silent-failure as #670: if the
  unlink fails, a partial file remains and the next `fetch()` trusts it as a
  complete download (it even skips the checksum path only if a checksum is
  configured — with no checksum, a partial file is used *directly*). Suggested
  fix: same treatment — `@unlink()` + return check + `error_log()` warning on
  failure. Related to FAQ-003 (e6fa1b2, #585) which explicitly says "never
  leave bytes behind that a later run will trust" — the warning gap is in the
  same spirit as this issue.
- **`tests/Phar/SfxDownloaderTest.php` (several assertions)** — the
  zip-path exception-message checks used only `assertStringContainsString`,
  which would not catch a future change that
  appends/wraps the message (the exact contract FAQ-003 pins). Tightened the
  corrupt-archive test to `assertSame`; the other zip tests still use
  `assertStringContainsString` — a follow-up could tighten them all, but that
  is noise for this issue.
- **`src/Phar/SfxDownloader.php` checksum path (lines 72–84)** — appends the
  removal note *into* the exception message only when removal succeeded. Fine
  per FAQ-003, but it means the two cleanup paths intentionally differ in
  their signal channel (message note vs. `error_log`). Not a bug; a candidate
  to unify in a future round if the class ever grows a logger.
