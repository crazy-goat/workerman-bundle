# Review round 3 — issue #615: config-cache permission warning must not throw via a strict error handler

Reviewed diff: `git log master..HEAD` (last two commits `c507186`, `e052b3d`; the earlier `5e15472` and `e3333cb` were reviewed in round 1). Read-only review; no files modified.

## Scope of review

- `src/ConfigLoader.php:186` — `error_log($verdict['warn'])` in the no-logger branch.
- `docs/security.md` — both no-logger-channel paragraphs (unreadable-metadata and opt-out downgrade) say `error_log()`.
- `tests/ConfigLoaderTest.php` — 40 tests; the only no-logger test left is the throwing-handler test (`testValidateCacheFilePermissionsDoesNotThrowWithThrowingErrorHandlerAndNoLogger`).
- `docs/proof_of_work/0615-.../findings-review.md`, `review-1.md`, `review-2.md` — round 1/2 records.
- Convergence check: leftover references to the old `E_USER_WARNING` no-logger channel as *current* behaviour in live code/docs.

## What I ran

- `vendor/bin/phpunit --no-coverage --filter ConfigLoaderTest tests/ConfigLoaderTest.php` — **40 tests, 96 assertions, OK** (3 skipped).
- `vendor/bin/phpstan analyse src/ConfigLoader.php tests/ConfigLoaderTest.php` — **No errors** (level 8).
- `vendor/bin/php-cs-fixer fix --dry-run --config=.php-cs-fixer.dist.php src/ConfigLoader.php tests/ConfigLoaderTest.php` — **0 files to fix** (PSR-12 clean).
- Grep for `E_USER_WARNING` / `trigger_error` across `src/`, `docs/security.md`, `README.md`, `CHANGELOG.md`, `docs/helpers/`.
- Did **not** run the E2E suite (binds ports 8888/9999).

## Round-1/2 findings status

| # | file:line | severity | status this round |
|---|-----------|----------|-------------------|
| F1 | docs/security.md:439, :598 | medium | **FIXED** — both paragraphs now say the no-logger channel is `error_log()` and explain why. Verified current text (lines 437-444 and 597-606). |
| F2 | tests/ConfigLoaderTest.php:514-535 | low | **RESOLVED** — the redundant no-logger test was removed (commit `e052b3d`); the throwing-handler test is a strict superset. |
| F3 | tests/ConfigLoaderTest.php:537-576 | nit | **FIXED** — `$userWarningInvocations` counter + `assertSame(0, ...)` directly asserts the handler was never invoked. Verified current text (lines 520, 531-541, 554). |
| F4 | tests/ConfigLoaderTest.php:514-535 | nit | **FIXED** — distinct capture files (`error-throwing.log` line 519, `error.log` line 936); no test reads another's output. |
| F5 | tests/ConfigLoaderTest.php:514-535 | low | **RESOLVED** — the false-confidence test was removed; the throwing-handler test genuinely pins the `error_log()` channel. |

## New findings

### F6 | CHANGELOG.md:35 | low | Stale `E_USER_WARNING` reference in the `[Unreleased]` #648 opt-out entry

The `[Unreleased]` entry for the `WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE` opt-out (#648) still says the downgraded refusal warnings are emitted as "PSR-3 `warning` or `E_USER_WARNING`". But the #648 downgrade warnings route through the **same** `$verdict['warn']` branch as the unreadable-metadata warning — `validateCacheFilePermissions()` line 182-188 — which now uses `error_log()` in the no-logger case (line 186). So the documented channel is stale: with no PSR-3 logger, the downgraded refusal is written via `error_log()`, not `trigger_error(E_USER_WARNING)`.

**Trigger:** a reader of `CHANGELOG.md` (the `[Unreleased]` section, i.e. current behaviour) relying on the documented warning channel for the opt-out path. The same stale claim was fixed in `docs/security.md` (F1) but missed in this CHANGELOG entry. The `testLoadFromCacheTriggersWarningViaErrorLogWhenTrustSetAndNoLogger` test (line 917) confirms the actual channel is `error_log()`.

**Severity:** low — documentation-only, no behaviour impact; the code and the security.md docs are correct. This is the only remaining stale reference to the old no-logger channel as current behaviour.

## Convergence check — leftover references

Grep results for `E_USER_WARNING` / `trigger_error` in live code/docs (excluding historical PoW):

- `src/ConfigLoader.php:132,135` — phpdoc explaining why `error_log()` is used *instead of* `trigger_error`. Correct (describes the rejected alternative, not current behaviour).
- `src/Http/Request.php:118` — `trigger_error(E_USER_DEPRECATED)`. Correctly left unchanged (deprecation must reach the handler; not the same defect).
- `docs/security.md:441` — "`error_log()` (not `trigger_error`)". Correct.
- `CHANGELOG.md:35` — **stale** (F6, see above).
- `CHANGELOG.md:119,123` — the #615 entry itself, describing the change from `trigger_error` to `error_log()`. Correct (historical record of the change).
- `CHANGELOG.md:356,358` — in the released `[0.26.0]` section (historical, out of scope).

No stale reference remains in `src/`, `docs/security.md`, `README.md`, or `docs/helpers/`. The only live-code/docs leftover is `CHANGELOG.md:35` (F6).

## Acceptance-criterion verification

The criterion — "a throwing handler still fires for unrelated warnings, and the fail-open warning must not be escalated to a hard failure" — is genuinely pinned:

- `src/ConfigLoader.php:186` uses `error_log()`, which does not invoke the PHP error handler.
- The throwing-handler test (line 514) proves (a) no exception escapes with a throwing handler installed, (b) the handler is never invoked for `E_USER_WARNING` (`assertSame(0, $userWarningInvocations)`), and (c) the warning still reaches the log via `error_log()`.
- The `@`-suppressed metadata reads are correctly handled (handler returns `true` for non-`E_USER_WARNING` severities, mirroring Symfony's suppression handling).
- The handler is restored in `finally`; `error_log` ini is restored in `finally`. No leak.

## KB / documented-decision compliance

Read the tag indexes of `docs/helpers/faq.md` and `docs/helpers/decisions.md` and the matching entries (`config-cache`, `permissions`, `env`, `runner`, `security`, `policy`, `tests`, `logging`): FAQ-005, FAQ-036, FAQ-008, DEC-006, DEC-016.

- **DEC-006 / DEC-016** — the change only alters the *channel* of the advisory warning, not any refusal; the "never silent" requirement still holds. Compliant.
- **FAQ-005 / FAQ-036** — no conflict; neither prescribes the warning channel.
- No KB entry documents the `error_log()` no-logger convention — the round-1/2 candidate DEC entry is still worth proposing (unchanged).

## Verdict

The review loop has **converged on the code and the security docs**: F1-F5 are all closed, and the fix genuinely pins the acceptance criteria. One **new low-severity documentation finding (F6)** remains: the `[Unreleased]` #648 CHANGELOG entry still describes the no-logger downgrade channel as `E_USER_WARNING`. It is documentation-only and does not affect behaviour, but it is the last stale reference to the old channel as current behaviour and should be corrected for a clean convergence.
