# code-decision-2 — issue #759, review round 1 fixes

## Approach

Address every actionable round-1 finding with the smallest diff that makes
the two env bridges structurally identical, and pin each fix with a
behavioral test so precedence can never silently diverge again.

- **F-1 (medium):** replaced the `??` chain in
  `WorkermanBundle::loadExtension()` with sequential null-or-blank checks
  that mirror `CacheWarmupTimeoutConfig::resolve()` — `$_SERVER`, then
  `$_ENV`, then `getenv()`. Both sides now share the exact same
  read order and fall-through semantics.
- **F-5 (low) + F-7 (nit), folded into the same edit:** both bridges trim
  before the emptiness check (whitespace-only → absent, falling through to
  the next channel, then to `DEFAULT` — matches the
  `ConfigCacheGuardConfig` sibling) and treat non-scalar superglobal values
  as absent rather than casting them. One edit, one test batch, no separate
  hardening pass needed. `(int)` cast semantics and the cast-value error
  message are untouched (F-4 stays deferred).
- **F-2 (medium):** added the RuntimeTest-style save/clear/restore block for
  all three env channels to `ServerManagerTest::setUp/tearDown`.
- **F-3 (low):** covered the `loadExtension()` bridge at the
  extension level in `WorkermanBundleIntegrationTest` (which routes through
  `AbstractBundle` → `WorkermanBundle::loadExtension()`): getenv-only
  override wins, empty/blank `$_SERVER` falls through to `$_ENV`,
  whitespace-only falls back to YAML. No new test class — the integration
  test already owned this bridge, the review just missed it (it was not in
  the round-1 diff). `setUp/tearDown` there now also isolates `getenv()`,
  which no test class did before.

## Rejected alternatives

- **Shared `readEnv()` helper on the holder:** would guarantee precedence
  parity structurally, but adds public/`@internal` API surface for two
  call sites that already duplicate the logic by design (the sibling guard
  duplicates it too). Kept the mirror-duplication pattern the reviewer
  prescribed; parity is now pinned by tests on both sides instead.
- **Strict `filter_var()` validation (F-4's suggestion):** deliberately not
  taken — behavior change beyond the #759 scope (would turn silent
  `"45.9"`→45 truncation into a throw on both paths). Left deferred as the
  reviewer agreed; noted in findings-coder.md.
- **New `WorkermanBundleTest` unit class:** rejected — calling
  `loadExtension()` directly needs `ContainerConfigurator` scaffolding, and
  the integration test exercises the real wiring (config tree → extension →
  holder), which is the stronger gate for this bridge.
- **CHANGELOG entry (F-6):** left for the main session (step 8) per the
  task brief; `CHANGELOG.md` untouched.
