# Review round 2 — issue #615: config-cache permission warning must not throw via a strict error handler

Reviewed diff: `git show c507186` (plus CHANGELOG commit `e3333cb`, already seen in round 1).
Read-only review; no files modified.

## Scope of review

- `docs/security.md` — both no-logger warning paragraphs updated to say `error_log()` (F1 fix).
- `tests/ConfigLoaderTest.php` — distinct capture files (F4 fix); `$userWarningInvocations` counter + `assertSame(0, ...)` (F3 fix).
- `docs/proof_of_work/0615-.../findings-review.md`, `review-1.md` — round-1 records.

## What I ran

- `vendor/bin/phpunit --no-coverage --filter ConfigLoaderTest tests/ConfigLoaderTest.php` — **41 tests, 99 assertions, OK** (3 skipped).
- The two reworked tests in isolation — **2 tests, 7 assertions, OK**.
- `vendor/bin/php-cs-fixer fix --dry-run --config=.php-cs-fixer.dist.php tests/ConfigLoaderTest.php` — **0 files to fix** (PSR-12 clean).
- `vendor/bin/phpstan analyse tests/ConfigLoaderTest.php` — **No errors** (level 8).
- Empirical check of PHP's default error handler: `trigger_error($path, E_USER_WARNING)` with `ini_set('error_log', $file)` and **no custom handler** writes the path to the configured log file (verified on PHP 8.5.9).
- Did **not** run the E2E suite (binds ports 8888/9999).

## KB / documented-decision compliance

Read the tag indexes of `docs/helpers/faq.md` and `docs/helpers/decisions.md` and the entries whose tags match the diff (`config-cache`, `permissions`, `env`, `runner`, `security`, `policy`, `tests`, `logging`): FAQ-005, FAQ-036, FAQ-006, FAQ-008, FAQ-014, FAQ-022, FAQ-024, FAQ-029, FAQ-035, DEC-006, DEC-016, DEC-007, DEC-009, DEC-014, DEC-015.

- **DEC-006 / DEC-016** — the change only alters the *channel* of the advisory warning, not any refusal; the "never silent" requirement still holds. Compliant.
- **FAQ-005 / FAQ-036** — no conflict; neither prescribes the warning channel. FAQ-036 does not mention `trigger_error` (round-1's `findings-coder.md` claim to the contrary remains inaccurate).
- No KB entry documents the `error_log()` no-logger convention — the round-1 candidate DEC entry is still worth proposing.

## Round-1 findings status

| # | file:line | severity | status this round |
|---|-----------|----------|-------------------|
| F1 | docs/security.md:439, :598 | medium | **FIXED** — both paragraphs now say `error_log()`; grep confirms no stale `E_USER_WARNING`-as-current-convention text remains in `docs/security.md`. Wording is accurate. |
| F2 | tests/ConfigLoaderTest.php:514-535 | low | **NOT FIXED — justification is factually wrong (re-opened, see F5).** |
| F3 | tests/ConfigLoaderTest.php:537-576 | nit | **FIXED** — `$userWarningInvocations` counter + `assertSame(0, ...)` directly asserts the handler was never invoked. Correct. |
| F4 | tests/ConfigLoaderTest.php:514-535 | nit | **FIXED** — three distinct capture files (`error-nologger.log`, `error-throwing.log`, `error.log`); no test reads another's output. Correct. |

## New findings

### F5 | tests/ConfigLoaderTest.php:514-535 | low | F2's "deliberately not fixed" justification is factually wrong — the no-logger test would NOT catch a `trigger_error` regression

The round-1 record (findings-review.md F2) justifies not fixing the no-logger test with: *"the test would still fail on a regression to `trigger_error` (the log would be empty)."* **This is false.** Empirically verified on PHP 8.5.9: with no custom error handler installed and `ini_set('error_log', $file)`, `trigger_error($path, E_USER_WARNING)` invokes PHP's **default** error handler, which writes the warning (including the path) to the configured `error_log` file. The log is **not** empty.

Consequence: if `src/ConfigLoader.php:186` regressed from `error_log($verdict['warn'])` back to `trigger_error($verdict['warn'], E_USER_WARNING)`, the no-logger test's `assertStringContainsString($missingPath, $logContent)` would still **pass** — the default handler would have written the path to the log. The test provides false confidence that it pins the `error_log()` channel; it only pins that *some* warning reaches the log.

**Trigger:** any future edit that reverts the no-logger channel to `trigger_error` while the throwing-handler test (F3) is the only guard. The real defect is still pinned by the throwing-handler test, so this is a test-quality gap, not a live code defect — but the stated reason for leaving F2 unfixed is wrong and should be corrected (either fix the test to install a throwing handler, or correct the justification).

## Other checks

- **security.md wording** (lines 441-444): "`error_log()` (not `trigger_error`) is used for the no-logger path so a throwing error handler (e.g. Symfony's `DebugErrorHandler` in debug mode) cannot turn the advisory fail-open warning into a hard boot failure." — accurate.
- **F3 fix correctness**: `$userWarningInvocations` is captured by reference (`use (&$userWarningInvocations)`), initialized to 0 before `set_error_handler`, incremented only on `E_USER_WARNING`, asserted `assertSame(0, ...)` after the `finally` restores the handler. Correct. (Note: if the code regressed to `trigger_error`, the handler would throw `ErrorException` and fail the test before reaching the assert — so the counter is a direct assertion that is partly redundant with the no-exception-escapes check, but it is valid and harmless.)
- **F4 fix correctness**: `error-nologger.log` (line 519), `error-throwing.log` (line 542), `error.log` (line 959) — all three capture-file users now write to distinct files. `tearDown()` removes `$tempDir`, so no cross-run leak. Correct.
- **`src/ConfigLoader.php:186`** — `error_log($verdict['warn'])` unchanged and correct; `error_log()` does not invoke the error handler.
- **`src/Http/Request.php:118`** — `trigger_error(E_USER_DEPRECATED)` correctly left unchanged (deprecation must reach the handler).
- **CHANGELOG** (e3333cb) — already reviewed in round 1; no change this round.

## Candidate KB entries

1. **DEC** — "No-logger warning channels use `error_log()`, not `trigger_error(E_USER_WARNING)`" (tags=logging,error-handler,policy). When a PSR-3 logger is not in scope and a path must surface an advisory warning without aborting control flow, use `error_log()`: it writes to the configured log and does not invoke the PHP error handler, so a throwing handler (Symfony `DebugErrorHandler` in debug mode) cannot escalate it to `ErrorException` — fail-open stays fail-open. `trigger_error` remains correct for emitted *deprecations* (`E_USER_DEPRECATED`) where reaching the handler is the point. Precedent: #670 (SfxDownloader) and #615 (ConfigLoader) independently chose the same rule; `ServerWorker`, `HttpRequestHandler`, `RequestConverter` already follow it.
2. **FAQ** — "`error_log()` capture in tests: use `ini_set('error_log', $file)` + read-back, restored in `finally`" (tags=tests,logging). The repo's established capture pattern (also in `HttpRequestHandlerTest`); substring-based asserts are robust against unrelated log lines.
3. **FAQ** — "PHP's default error handler writes `trigger_error(E_USER_WARNING)` to the configured `error_log` — a test that captures `error_log` output does NOT distinguish `error_log()` from `trigger_error()` unless a custom handler is installed" (tags=tests,logging,error-handler). A test asserting "the warning reached the log" passes for both channels; to pin the channel, install a throwing handler (or assert the handler was not invoked). Discovered in #615 round 2 (F5).
