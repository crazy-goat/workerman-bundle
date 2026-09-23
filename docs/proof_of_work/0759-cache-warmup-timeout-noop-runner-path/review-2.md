# review-2 — issue #759: `WORKERMAN_CACHE_WARMUP_TIMEOUT` no-op on the Runner path

Branch: `fix/issue-759-workerman-cache-warmup-timeout-is-a-no-o`, diff vs `origin/master`.
Round-2 delta (since round 1, commit `02b1d64..HEAD`): `src/CacheWarmupTimeoutConfig.php`,
`src/WorkermanBundle.php`, `tests/CacheWarmupTimeoutConfigTest.php`,
`tests/DependencyInjection/WorkermanBundleIntegrationTest.php`, `tests/ServerManagerTest.php`.
(`tests/RuntimeTest.php` is in the master diff but untouched since round 1.)

## 1. docs/helpers/ — tag index + entries read

Tag index loaded from both files; tags matching the diff: `env`, `runner`,
`config-cache`, `config`, `tests`, `policy`, `changelog`, `coverage`.
Entries read: **FAQ-036** (config-cache,permissions,env,runner), **FAQ-035**
(config,tests,symfony-config — considered, ruled out: no `min()`/`max()` bound
changed), **DEC-016** (security,policy,config-cache,env), **DEC-007**
(coverage,ci,policy), **DEC-014** (memory,long-running,http,tests), **DEC-021**
(docs,upgrade,changelog).

**Violations of documented decisions: none.**
- FAQ-036 prescribes exactly this fix (lazy `resolve()` over
  `$_SERVER`/`$_ENV`/`getenv()` with `set()` as override, because
  `Runtime::getRunner()` runs pre-boot). The diff implements it on both paths.
- DEC-016 (env-over-YAML for pre-boot paths; fail-closed parsing; strict
  default) is honoured: invalid env values throw fail-fast on both paths
  instead of silently defaulting on one.
- DEC-007: no coverage-threshold change. DEC-014: the static holder keeps its
  `reset()` test affordance; not a keyed cache, no eviction pillar applies.
- DEC-021: CHANGELOG `[Unreleased]` still empty — flagged as F-6 (maintainer
  call), not a violation per se.

## 2. Earlier findings (F-1..F-7) — status vs round 1

Detail with evidence in `findings-review.md` (appended, nothing deleted):

- F-1 (medium) — **fixed.** `src/WorkermanBundle.php:97-105` now uses
  sequential `!is_scalar || trim() === ''` checks mirroring `resolve()`;
  verified by repro (`''`+`'55'` → `55`) and pinned by
  `testLoadExtensionEmptyServerFallsThroughToEnv`.
- F-2 (medium) — **fixed.** `tests/ServerManagerTest.php` setUp/tearDown now
  save/clear/restore all three channels; the exported-var repro
  (`WORKERMAN_CACHE_WARMUP_TIMEOUT=77 ... --filter
  testResolveCacheWarmupTimeoutDefaultsWhenHolderEmpty`) passes.
- F-3 (low) — **fixed.** `testLoadExtensionReadsGetenvOnlyOverride`
  (`tests/DependencyInjection/WorkermanBundleIntegrationTest.php:282`) pins
  the getenv-only branch; plus three more precedence tests.
- F-4 (low) — **still present, deliberate.** `(int)` truncation (`"45.9"`→`45`,
  repro-confirmed) and cast-value-in-message persist on both paths; now
  explicitly pinned by `testLoadExtensionFloatEnvOverrideIsTruncated`
  (`'3.7'`→`3`), so it is specified behavior, not an accident. Coder-deferred
  for parity (see `findings-coder.md`); strict-validation remains a
  maintainer call.
- F-5 (low) — **fixed.** Whitespace-only treated as absent on both paths
  (`trim()` checks); repro `'   '` → `30`; pinned by
  `testResolveTreatsWhitespaceOnlyEnvVarAsAbsent` and
  `testLoadExtensionWhitespaceOnlyEnvOverrideFallsBackToConfig`.
- F-6 (nit) — **still present.** `CHANGELOG.md:8` `[Unreleased]` still empty.
  Maintainer call whether this fix warrants an entry (DEC-021).
- F-7 (nit) — **fixed.** Non-scalar superglobal values guarded by
  `is_scalar()` on both paths (`src/CacheWarmupTimeoutConfig.php:61-74`,
  `src/WorkermanBundle.php:97-105`); repro array value → `DEFAULT` (`30`).

## 3. New issues

- N-1 (low) — env-bridge logic now duplicated in `resolve()` and
  `loadExtension()` (three sequential checks each, evolving in lockstep this
  round). They already drifted once (F-1); next edit must touch both.
  Consider a shared `@internal` helper (e.g. `readEnvRaw(): mixed`) — optional.
- N-2 (nit) — `testLoadExtensionDoesNotMutateServerSuperglobal`
  (`WorkermanBundleIntegrationTest.php:132-147`) restores with
  `$_SERVER[...] = $savedServer` even when `$savedServer` is `null`, leaving a
  null-valued key until tearDown cleans it. Harmless (tearDown restores the
  setUp snapshot) but `unset`-on-null would be tidier.

## 4. Checks run (serialized, per FAQ-039 — no commits)

- `vendor/bin/phpunit tests/CacheWarmupTimeoutConfigTest.php
  tests/DependencyInjection/WorkermanBundleIntegrationTest.php
  tests/ServerManagerTest.php` — OK (88 tests, 183 assertions, 1 skipped;
  suffix is the pre-existing XDEBUG-coverage notice)
- `vendor/bin/phpunit tests/RuntimeTest.php` — OK (9 tests, 13 assertions)
- `WORKERMAN_CACHE_WARMUP_TIMEOUT=77 vendor/bin/phpunit
  tests/ServerManagerTest.php --filter
  testResolveCacheWarmupTimeoutDefaultsWhenHolderEmpty` — OK (F-2 repro, now
  passes); same exported-var run against `CacheWarmupTimeoutConfigTest` — OK
- `php -r` repro vs `resolve()`: empty-SERVER+ENV55 → `55` (F-1 fixed),
  whitespace-only → `30` (F-5 fixed), array value → `30` (F-7 fixed),
  `"45.9"` → `45` (F-4 still present by design)
- `vendor/bin/phpstan analyse` — no errors
- `vendor/bin/php-cs-fixer fix --dry-run --config=.php-cs-fixer.dist.php` —
  clean (0 of 259 fixable)

## 5. Docs-helper candidates proposed (not written — retro owns `docs/helpers/`)

1. **Append resolution note to FAQ-036** (as the coder proposed): lazy
   `resolve()` landed for `CacheWarmupTimeoutConfig`; `set()` wins post-boot.
   Suggested tags: keep `config-cache,permissions,env,runner`; trigger: keep
   existing. (Carried over from round 1.)
2. **New FAQ entry — `??` does not skip `''` when bridging the three env
   channels** (carried over from round 1; F-1 is the specimen, now fixed and
   pinned by `testLoadExtensionEmptyServerFallsThroughToEnv`). Per README
   rule 4 (prefer a gate over an entry): the precedence tests ARE the gate
   now — prefer skipping/shrinking this entry.
