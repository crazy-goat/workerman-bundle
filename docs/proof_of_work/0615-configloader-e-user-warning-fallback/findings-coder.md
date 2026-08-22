# findings-coder — issue #615: config-cache permission warning must not throw via a strict error handler

## Obstacles and surprises

1. **The search tool's grep mode misses raw `error_log(` calls.** `find`
   grep-mode returns 0 hits for `error_log(` while `grep -rn error_log src/`
   finds all of them; only `trigger_error` matched. (The error-log convention
   references in the task and in `0670/code-decision-1.md` turned out to be
   accurate once verified with a plain shell grep — the fixture exists.
   Informational: use literal grep, not the tool's grep, when a search
   unexpectedly returns zero.)

2. **The current tests asserted the *handler was invoked*, which is the exact
   behavior being removed.** Both reworked tests originally installed a
   non-throwing `set_error_handler` to *capture* the `E_USER_WARNING`. After
   the fix the warning goes to `error_log`, so those assertions would have
   silently stopped firing (the `$triggered` closure would stay null and the
   `assertIsString` would fail). The rework had to assert on captured log
   output instead — same class of change the repo already went through for
   #670.

3. **`error_log` capture must not use a `php://` wrapper.** The task
   explicitly asked for a real temp file. The repo's own fixture uses
   `tempnam(sys_get_temp_dir(), ...)`; I used a real file in the test's
   already-cleaned `$tempDir` (`$tempDir/error.log`) instead, so `tearDown()`
   cleans it up and no manual `unlink()` is needed. This kept the three tests
   self-contained and idempotent under reruns.

## trigger_error audit (same-class / identical fail-open contexts)

| File:line | What | Verdict |
|---|---|---|
| `src/ConfigLoader.php:179` (before fix) | the no-logger fail-open `trigger_error(\E_USER_WARNING)` | **Changed** (this issue) |
| `src/Http/Request.php:118` (`withHeader()`) | `trigger_error(..., E_USER_DEPRECATED)` for a deprecated method | **Not the same defect.** A deprecation signal is a *different* kind of warning (`E_USER_DEPRECATED`, not a fail-open no-logger channel) and is not reached during the permission check. It must still reach the (possibly user-installed) handler so deprecations are visible in CI/dev. Left unchanged — out of scope. |
| `Runner`, `ServerManager` | no `trigger_error` found on the fail-open paths; `tests/RunnerTest.php:839` confirms the Runner path logs via the injected logger. | No change. |

## Bugs / weak spots noticed (in and out of scope)

| File:line | What | Suggested fix |
|---|---|---|
| `src/ConfigLoader.php:179` (pre-fix) | The no-logger fail-open warning used `trigger_error(\E_USER_WARNING)`, which a strict handler escalates to `ErrorException` — turning fail-open into a hard boot failure. | Fix lands here: switch to `error_log()`. |
| `docs/helpers/` | `FAQ-036` and the `0612` PoW describe the `trigger_error(\E_USER_WARNING)` fallback as the current no-logger convention for this path. | Update on merge of #615 so the KB does not recommend the abolished pattern. (KB-owned — main session decides.) |
| `CHANGELOG.md:346` | Mentions "the `trigger_error(\E_USER_WARNING)` fallback is preserved for the Runner path" in an older release note. | Historical record; leave unchanged. |
| `tests/ConfigLoaderTest.php` (pre-existing) | A throwing `set_error_handler` would previously have crashed the whole suite if any earlier warning fired; the new dedicated test installs one briefly and restores it in `finally` — nothing to fix, but this is why the "no leaking handler" `finally` matters. | None. |

## Knowledge-base candidates (propose; main session decides)

1. **DEC:** "No-logger warning channels use `error_log()`, not
   `trigger_error(\E_USER_WARNING)`" — tags=logging,error-handler,policy,
   trigger="adding or touching a no-logger warning/fallback path, or a
   `trigger_error` call". Paragraph: when a PSR-3 logger is not in scope and
   a code path must surface an advisory warning without aborting control
   flow, use `error_log()` rather than `trigger_error(\E_USER_WARNING)`.
   `error_log()` writes directly to the configured log and does not invoke
   the PHP error handler, so it cannot be escalated to an `ErrorException` by
   a strict handler (Symfony `DebugErrorHandler` in debug mode) — an advisory
   warning stays advisory. `trigger_error` remains correct for emitted
   *deprecations* (`E_USER_DEPRECATED`) where reaching the handler is the
   point. Precedent: issue #670 (SFX downloader) independently chose the same
   rule, and this issue (#615) aligns `ConfigLoader` with the codebase
   convention (`ServerWorker`, `HttpRequestHandler`, `RequestConverter`,
   `SfxDownloader`).
