# Review round 1 — issue #615: config-cache permission warning must not throw via a strict error handler

Reviewed diff: `git log master..HEAD` (commits `5e15472`, `e3333cb`).
Read-only review; no files modified.

## Scope of review

- `src/ConfigLoader.php` — `error_log()` replaces `trigger_error(E_USER_WARNING)` in the no-logger branch; phpdoc updated.
- `tests/ConfigLoaderTest.php` — two tests reworked to capture `error_log()`; one new throwing-handler test.
- `CHANGELOG.md` — new entry under `[Unreleased] > Fixed`.
- `docs/proof_of_work/0615-.../code-decision-1.md`, `findings-coder.md`.

## What I ran

- `vendor/bin/phpunit --no-coverage --filter ConfigLoaderTest tests/ConfigLoaderTest.php` — **41 tests, 98 assertions, OK** (3 skipped).
- The three reworked/new tests in isolation — **3 tests, 11 assertions, OK**.
- `vendor/bin/phpstan analyse src/ConfigLoader.php tests/ConfigLoaderTest.php` — **No errors** (level 8).
- `vendor/bin/php-cs-fixer fix --dry-run --config=.php-cs-fixer.dist.php src/ConfigLoader.php tests/ConfigLoaderTest.php` — **0 files to fix** (PSR-12 clean).
- `vendor/bin/rector process --dry-run src/ConfigLoader.php tests/ConfigLoaderTest.php` — **OK**.
- `php bin/kb-lint.php` — OK (1 pre-existing budget warning on faq.md, unrelated).
- `php bin/check-changelog.php` — OK (structurally valid).
- Did **not** run the E2E suite (binds ports 8888/9999).

## KB / documented-decision compliance

Read the tag indexes of `docs/helpers/faq.md` and `docs/helpers/decisions.md` and the entries whose tags match the diff (`config-cache`, `permissions`, `env`, `runner`, `security`, `policy`, `tests`, `logging`): FAQ-005, FAQ-036, FAQ-006, FAQ-008, FAQ-014, FAQ-022, FAQ-035, FAQ-024, FAQ-029, DEC-006, DEC-016, DEC-007, DEC-009, DEC-014, DEC-015.

- **DEC-006** (cache permissions hardening) — not loosened; the change only alters the *channel* of the advisory warning, not any refusal. Compliant.
- **DEC-016** (opt-outs fail-closed, keep strict default, every degraded check keeps emitting its warning) — the downgrade still emits its warning (now via `error_log()`), so the "never silent" requirement holds. Compliant.
- **FAQ-005 / FAQ-036** — no conflict; neither prescribes the warning channel.
- **No KB entry documents the `error_log()` no-logger convention** — the coder's `findings-coder.md` claims FAQ-036 describes the `trigger_error` fallback as current convention; **that claim is inaccurate** (FAQ-036 does not mention `trigger_error`). The real stale references are in `docs/security.md` (see findings) and the `0612` PoW (historical, out of scope).

## Findings

See `findings-review.md` for the full list. Summary of open items:

| # | file:line | severity | description |
|---|-----------|----------|-------------|
| F1 | docs/security.md:439, :598 | medium | Docs still say the no-logger warning is "raised as an `E_USER_WARNING`" — now false after the fix. |
| F2 | tests/ConfigLoaderTest.php:514-535 | low | `testValidateCacheFilePermissionsLogsWarningWhenMetadataUnreadableAndNoLogger` does not install a throwing handler, so it would pass even if the code regressed to `trigger_error` (the handler-invocation assertion was removed). The dedicated throwing-handler test covers this, so it is a redundancy gap, not a correctness gap. |
| F3 | tests/ConfigLoaderTest.php:537-576 | nit | The throwing-handler test does not assert the handler was *not* invoked (only that no exception escaped and the log got the message). If a future change routed the warning through a non-throwing handler *and* `error_log`, the test would still pass. |
| F4 | tests/ConfigLoaderTest.php:514-535 | nit | `error_log` capture is not cleared between the two tests that reuse `$tempDir/error.log`; the second test's `assertStringContainsString` is substring-based so it is robust, but the file accumulates across tests. |

## Throwing-handler test effectiveness

`testValidateCacheFilePermissionsDoesNotThrowWithThrowingErrorHandlerAndNoLogger` (tests/ConfigLoaderTest.php:537) is **genuinely effective**:

- The handler is installed with default `error_types` (`E_ALL|E_STRICT`), so it is invoked for `E_USER_WARNING`. Against the old `trigger_error` code it would throw `ErrorException`, which escapes the reflection invoke and fails the test. Against the new `error_log()` code no exception escapes.
- The `@`-suppressed metadata reads (`@fileperms`/`@filegroup`/`@fileowner`) call the handler with `error_reporting()==0`; the handler returns `true` for non-`E_USER_WARNING` severities, so they do not throw — correctly mirroring Symfony's `DebugErrorHandler` suppression handling. The handler is scoped correctly.
- The `assertStringContainsString($missingPath, $logContent)` proves the warning reached the log, so the test verifies both halves of the acceptance criterion (no exception escapes AND the warning is still surfaced).
- The handler is restored in `finally`, so no handler leaks into the rest of the suite.

The one gap (F3) is that the test does not *directly* assert the handler was never invoked, but the no-exception-escapes assertion is a sufficient proxy for the defect in question.

## Other `trigger_error` call sites

Audited all `trigger_error` in `src/`:
- `src/Http/Request.php:118` — `E_USER_DEPRECATED` for `withHeader()`. **Not the same defect**: a deprecation is a different signal class, is not a fail-open no-logger channel, and must still reach the handler to be visible in CI/dev. Correctly left unchanged.
- No other fail-open no-logger `trigger_error` call sites exist. The `error_log()` convention is already used by `ServerWorker`, `HttpRequestHandler`, `RequestConverter`, `SfxDownloader`.

## CHANGELOG

The entry (CHANGELOG.md:118-126) is under `[Unreleased] > Fixed`, describes the behaviour change and rationale, and links issue #615. Compliant with Keep a Changelog. `check-changelog.php` passes.

## Candidate KB entries

1. **DEC** — "No-logger warning channels use `error_log()`, not `trigger_error(E_USER_WARNING)`" (tags=logging,error-handler,policy). When a PSR-3 logger is not in scope and a path must surface an advisory warning without aborting control flow, use `error_log()`: it writes to the configured log and does not invoke the PHP error handler, so a throwing handler (Symfony `DebugErrorHandler` in debug mode) cannot escalate it to `ErrorException` — fail-open stays fail-open. `trigger_error` remains correct for emitted *deprecations* (`E_USER_DEPRECATED`) where reaching the handler is the point. Precedent: #670 (SfxDownloader) and #615 (ConfigLoader) independently chose the same rule; `ServerWorker`, `HttpRequestHandler`, `RequestConverter` already follow it.
2. **FAQ** — "`error_log()` capture in tests: use `ini_set('error_log', $file)` + read-back, restored in `finally`" (tags=tests,logging). The repo's established capture pattern (also in `HttpRequestHandlerTest`); substring-based asserts are robust against unrelated log lines.
