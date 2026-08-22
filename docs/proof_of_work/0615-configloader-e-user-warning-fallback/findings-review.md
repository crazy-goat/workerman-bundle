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

---

# Findings review — issue #615 (round 2)

Round 2 reviewed `git show c507186` (docs/security.md, tests/ConfigLoaderTest.php, PoW records). Status of round-1 findings verified; one new finding (F5).

## Round-1 status updates

### F1 | docs/security.md:439, :598 | medium | Outdated documentation

**→ FIXED (verified round 2).** Both paragraphs now say the no-logger channel is `error_log()` and explain why. Grep confirms no stale `E_USER_WARNING`-as-current-convention text remains in `docs/security.md`. Wording accurate.

### F2 | tests/ConfigLoaderTest.php:514-535 | low | Redundancy gap in the reworked no-logger test

**→ NOT FIXED — justification is factually wrong (re-opened as F5).** The round-1 record claimed the test "would still fail on a regression to `trigger_error` (the log would be empty)". Empirically false: PHP's default error handler writes `trigger_error(E_USER_WARNING)` to the configured `error_log` file, so the log is not empty and the test would still pass on a `trigger_error` regression.

### F3 | tests/ConfigLoaderTest.php:537-576 | nit | Throwing-handler test does not assert the handler was never invoked

**→ FIXED (verified round 2).** `$userWarningInvocations` counter (captured by reference) + `assertSame(0, ...)` directly asserts the handler was never invoked. Correct.

### F4 | tests/ConfigLoaderTest.php:514-535 | nit | `error.log` capture file not cleared between tests

**→ FIXED (verified round 2).** Three distinct capture files: `error-nologger.log` (line 519), `error-throwing.log` (line 542), `error.log` (line 959). No test reads another's output. Correct.

## New findings

### F5 | tests/ConfigLoaderTest.php:514-535 | low | F2's "deliberately not fixed" justification is factually wrong — the no-logger test would NOT catch a `trigger_error` regression

The round-1 record (F2) justifies leaving the no-logger test unfixed with: *"the test would still fail on a regression to `trigger_error` (the log would be empty)."* **This is false.** Empirically verified on PHP 8.5.9: with no custom error handler installed and `ini_set('error_log', $file)`, `trigger_error($path, E_USER_WARNING)` invokes PHP's **default** error handler, which writes the warning (including the path) to the configured `error_log` file. The log is **not** empty.

Consequence: if `src/ConfigLoader.php:186` regressed from `error_log($verdict['warn'])` back to `trigger_error($verdict['warn'], E_USER_WARNING)`, the no-logger test's `assertStringContainsString($missingPath, $logContent)` would still **pass** — the default handler would have written the path to the log. The test provides false confidence that it pins the `error_log()` channel; it only pins that *some* warning reaches the log.

**Trigger:** any future edit that reverts the no-logger channel to `trigger_error` while the throwing-handler test (F3) is the only guard. The real defect is still pinned by the throwing-handler test, so this is a test-quality gap, not a live code defect — but the stated reason for leaving F2 unfixed is wrong and should be corrected (either fix the test to install a throwing handler, or correct the justification).

**→ RESOLVED (round 2 fix).** The redundant `testValidateCacheFilePermissionsLogsWarningWhenMetadataUnreadableAndNoLogger` test — which, as F5 showed, provided false confidence by passing even on a `trigger_error` regression — was **removed**. The throwing-handler test (`testValidateCacheFilePermissionsDoesNotThrowWithThrowingErrorHandlerAndNoLogger`) is a strict superset: it proves (a) no exception escapes with a throwing handler installed, (b) the handler is never invoked for `E_USER_WARNING` (`$userWarningInvocations === 0`), and (c) the warning still reaches the log via `error_log()`. It genuinely pins the `error_log()` channel. ConfigLoaderTest now has 40 tests (was 41), all green. F2's incorrect justification and F5 are both closed by this removal.
