# Findings review — issue #615 (round 1)

One entry per finding: file:line, what is wrong, severity, and what happened to it.
This is the first review round; `findings-review.md` did not previously exist, so all entries are new.

## Open findings

### F1 | docs/security.md:439, :598 | medium | Outdated documentation

The no-logger warning channel is still documented as "raised as an `E_USER_WARNING` otherwise" in two places in `docs/security.md` (the unreadable-metadata paragraph and the opt-out downgrade paragraph). After this fix the no-logger channel is `error_log()`, so the docs are now false. A reader following the docs would expect the warning to reach the PHP error handler (and, under Symfony debug mode, to throw) — the exact behaviour the fix removes.

**Trigger:** any reader of `docs/security.md` relying on the documented warning channel. **Not covered by the diff** (the diff only updates the phpdoc in `src/ConfigLoader.php` and the CHANGELOG).

**→ FIXED.** Both paragraphs in `docs/security.md` updated to say the no-logger channel is `error_log()` and to explain why (a throwing error handler must not turn the advisory warning into a hard boot failure).

### F2 | tests/ConfigLoaderTest.php:514-535 | low | Redundancy gap in the reworked no-logger test

`testValidateCacheFilePermissionsLogsWarningWhenMetadataUnreadableAndNoLogger` captures `error_log()` output but installs no throwing handler. It asserts only that the message reached the log. If the code regressed to `trigger_error(E_USER_WARNING)` (the old behaviour), this test would still pass — the warning would be emitted to the handler, not the log, and `assertStringContainsString($missingPath, $logContent)` would fail on the empty log. So it *would* catch a regression to `trigger_error` (the log would be empty). The gap is narrower: it would not distinguish `error_log()` from a hypothetical non-throwing-handler routing. The dedicated throwing-handler test (F3's subject) covers the actual defect, so this is a redundancy gap, not a correctness gap.

**→ NOT FIXED (deliberately).** This is a redundancy gap, not a correctness gap: the test would still fail on a regression to `trigger_error` (the log would be empty). The actual defect is pinned by F3's throwing-handler test. Keeping a plain no-logger smoke test alongside is intentional.

### F3 | tests/ConfigLoaderTest.php:537-576 | nit | Throwing-handler test does not assert the handler was never invoked

The test proves (a) no exception escapes and (b) the warning reached the log, but does not directly assert the handler was not invoked. If a future change routed the warning through a non-throwing handler *and* `error_log()`, the test would still pass. The no-exception-escapes assertion is a sufficient proxy for the current defect, so this is a nit, not a blocker.

**→ FIXED.** The handler now increments a `$userWarningInvocations` counter on `E_USER_WARNING`; the test asserts `assertSame(0, $userWarningInvocations)` — proving the fail-open warning never reaches the error handler.

### F4 | tests/ConfigLoaderTest.php:514-535 | nit | `error.log` capture file not cleared between tests

Two tests reuse `$tempDir/error.log` (the no-logger test and the throwing-handler test). The file is not truncated between them, so the second test's log content includes the first test's line. The `assertStringContainsString` assertions are substring-based, so this is robust, but the file accumulates across tests. `tearDown()` removes `$tempDir`, so there is no cross-run leak.

**→ FIXED.** The three capture-file users now each write to a distinct file: `error-nologger.log`, `error-throwing.log`, and `error.log`, so no test reads another's output.

## Resolved / not-a-finding

- **`src/ConfigLoader.php:186` — `error_log($verdict['warn'])`** — correct. `error_log()` does not invoke the PHP error handler, so a throwing handler cannot escalate it. Matches the codebase convention (`ServerWorker`, `HttpRequestHandler`, `RequestConverter`, `SfxDownloader`). No finding.
- **`src/Http/Request.php:118` — `trigger_error(E_USER_DEPRECATED)`** — not the same defect. A deprecation is a different signal class, not a fail-open no-logger channel, and must still reach the handler. Correctly left unchanged. No finding.
- **CHANGELOG entry (CHANGELOG.md:118-126)** — under `[Unreleased] > Fixed`, describes behaviour and rationale, links #615. `check-changelog.php` passes. No finding.
- **phpdoc update (src/ConfigLoader.php:128-137)** — accurate and complete. No finding.
- **`findings-coder.md` claim that FAQ-036 describes the `trigger_error` fallback as current convention** — inaccurate; FAQ-036 does not mention `trigger_error`. The real stale references are in `docs/security.md` (F1) and the `0612` PoW (historical, out of scope). Not a code defect; noted for the KB proposal.
- **Type correctness / PSR-12 / PHPStan level 8** — all clean (see review-1.md "What I ran"). No finding.
