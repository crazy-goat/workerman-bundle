# Code decision — round 1 (issue #670)

## Problem

The zip-extraction catch in `SfxDownloader::fetch()` (formerly
`src/Phar/SfxDownloader.php:92`) unlinked the failed artifact with a bare
`unlink($destination)` — no `@`, no return-value check, no signal. When
`unlink()` fails (read-only mount, ownership change, SELinux denial), an
`E_WARNING` is emitted but control flow continues to `throw $e;` with the
original message. The bad zip stays on disk and the next `fetch()` short-
circuits on `is_file($destination)` and fails on the same bad archive —
the exact self-perpetuating loop #642 was filed to fix (see FAQ-003).

The checksum path (same method, ~lines 72–84) already does it right:
`@unlink()` + return-value check + message note appended **only** on success.

## Approach taken

Mirror the checksum path:

```php
if (is_file($destination)) {
    $removed = @unlink($destination);
    if (!$removed) {
        error_log(sprintf(
            'Unable to remove failed SFX archive "%s"; the bad artifact stays on disk and every subsequent fetch() will fail on it. Remove it manually.',
            $destination,
        ));
    }
}
throw $e;
```

Three decisions inside this:

1. **`@unlink()` + return-value check** — identical to the checksum path, so
   no `E_WARNING` noise, and the failure is detected instead of assumed
   successful. The checksum path was *not* touched: it keeps its message-note
   behavior (FAQ-003 documents that only the checksum path appends the
   removal note).
2. **`error_log()` as the warning channel, not `trigger_error()`** — see
   "What I rejected".
3. **The rethrown exception stays byte-identical**: no message prefix, no
   note, no wrapping. `throw $e;` rethrows the original instance, so type
   (`SfxExtractionException` extends `\RuntimeException`) and message are
   preserved exactly. The new test asserts the message with `assertSame`
   against the known-good string.

### Logger question (task asked to check)

`SfxDownloader` has **no logger in scope**: it is `final readonly` with a
single `maxDownloadBytes` constructor parameter, registered as a plain
service (`workerman.sfx_downloader`) and consumed by the CLI
`BuildBinCommand`. The task explicitly said not to grow the constructor
signature. The class's own error-reporting convention is *throwing* — not
available here because the exception must stay unchanged. The codebase-wide
no-logger convention is a direct `error_log()` call (see
`src/Worker/ServerWorker.php:87-89` — "PSR-3 logger is not easily reachable
here", `src/Http/HttpRequestHandler.php:193/272`, `src/DTO/RequestConverter
.php:285/293`). I followed that.

I rejected `trigger_error(..., E_USER_WARNING)` (the `ConfigLoader` no-logger
convention, `src/ConfigLoader.php:151`) deliberately: it invokes the PHP
error handler, and a strict handler (e.g. Symfony's `DebugErrorHandler` in
debug mode) converts warnings into `ErrorException`, which **would replace
the rethrown `\RuntimeException`** — violating the "original type and message
exactly" contract. `error_log()` writes directly to the configured log and
cannot throw, so control flow is safe inside the catch.

### Test approach

The unlink-failure path is hard to test portably (the class is `final
readonly`, `unlink()` is called directly — nothing to mock; permission-based
failure doesn't work as root or on Windows). Chosen approach: a real
integration-style unit test that

- puts a corrupt zip in a directory, `chmod 0555` the directory (unlink
  needs write on the dir; `is_file` only needs execute),
- skips itself when the dir stays writable (`is_writable($dir)` — true for
  root or mode-bit-ignoring filesystems),
- captures `error_log()` output via `ini_set('error_log', ...)` — the exact
  pattern already used in `tests/HttpRequestHandlerTest.php:927-945`,
- asserts the rethrown message is byte-identical (`assertSame`) and that the
  log contains the removal-failure warning, and that the file still exists.

I also tightened the existing `testExtractZipRemovesCorruptArchiveWhenOpenFails`
assertion from `assertStringContainsString('Failed to open zip archive')` to
`assertSame` with the full message — cheap regression guard for the
"nothing false claimed" contract on the success-removal path.

## What I rejected

- **`trigger_error(E_USER_WARNING)`** — can throw via a strict error handler
  and hijack the rethrow (see above).
- **Appending a note to the exception message** — violates the task contract
  and FAQ-003 ("zip-extraction failures rethrow the original exception (type
  and message preserved)").
- **Adding a `LoggerInterface` constructor parameter** — explicitly ruled out
  by the task; also `final readonly` + DI registration change for one log
  line is disproportionate, and `BuildBinCommand` would need the arg too.
- **A mock/reflection-based unit test** — `SfxDownloader` is `final`, `fetch()`
  calls `unlink()` directly; there is no seam to inject. The real
  permission-based test is stronger anyway.
- **`chattr +i` / `chflags uchg`** (immutable-bit tricks so unlink fails even
  as root) — Linux/macOS specific, needs e2fs/APFS flag support; the
  `is_writable` skip guard covers the root case far more simply.

## What I was unsure about

- Whether `is_writable($dir)` is a fully reliable "are we root?" detector on
  every CI filesystem (ACLs could in theory make a 0555 dir writable for the
  process — then the test would fail rather than skip, loudly). Acceptable:
  the controlled test environments run either as non-root (test executes) or
  root (test skips). The corrupt-archive message is deterministic, so the
  `assertSame` cannot flake.
- Whether `error_log()`'s output lands in the `error_log` ini target on every
  SAPI — it does for message_type 0, and the repo's own tests already rely on
  it.
