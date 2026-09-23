# code-decision-3 — issue #759, review round 2 fixes (N-1, N-2)

## Approach

Minimal diff, behavior-identical extraction plus a test-hygiene fix.

- **N-1 (low):** extracted the three-channel env bridge into
  `CacheWarmupTimeoutConfig::readEnvRaw(): ?string` (`@internal`, returns the
  trimmed raw string or `null`). `resolve()` and
  `WorkermanBundle::loadExtension()` both call it now, so precedence
  (`$_SERVER` → `$_ENV` → `getenv()`, trim, empty→absent, non-scalar→absent)
  lives in exactly one place and cannot drift again (the F-1 class of bug).
  The `(int)` cast and cast-value error message are untouched (F-4 parity).
- **N-2 (nit):** `testLoadExtensionDoesNotMutateServerSuperglobal` now
  `unset()`s the `$_SERVER` key on restore when the saved value was absent,
  instead of leaving a null-valued key until `tearDown()`.
- **F-4, F-6:** left untouched per brief (deliberate / main-session
  changelog job). `CHANGELOG.md` not edited; `findings-review.md` statuses
  not touched.

## Rejected alternatives

- **Returning `mixed` / untrimmed raw from the helper:** the brief asks for
  `?string` trimmed-or-null, and both call sites need the trimmed string
  anyway; returning untrimmed would just move the `trim()` duplication
  outward. Kept trim-inside.
- **Sharing the whole resolve/set logic:** rejected — `loadExtension()`
  falls back to YAML while `resolve()` falls back to `DEFAULT`, so only the
  raw-read step is shareable, as the reviewer already noted.

## Verification

- `vendor/bin/phpunit tests/CacheWarmupTimeoutConfigTest.php
  tests/DependencyInjection/WorkermanBundleIntegrationTest.php
  tests/ServerManagerTest.php` — OK (88 tests, 183 assertions, 1 skipped;
  skip + warning are the pre-existing XDEBUG-coverage notice).
- `vendor/bin/phpunit tests/RuntimeTest.php` — OK (9 tests, 13 assertions).
- `vendor/bin/phpstan analyse` — no errors.
- `vendor/bin/php-cs-fixer fix --dry-run` on the 3 touched files — clean.
- `php -r` repro: `$_SERVER=''` + `$_ENV='55'` → `readEnvRaw()='55'`,
  `resolve()=55`; whitespace-only → `null` / `30` (DEFAULT).
- Helpers: tag index loaded from both files; entries read: FAQ-036
  (prescribes lazy resolve/set-override — helper keeps it), DEC-016
  (env-over-YAML pre-boot, fail-closed — unchanged), DEC-007 (no threshold
  change), DEC-014 (static holder `reset()` affordance kept; no keyed cache),
  DEC-021 (CHANGELOG left for maintainer). FAQ-035 considered, ruled out
  (no bound changed). No violations.
