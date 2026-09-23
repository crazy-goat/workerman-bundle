# review-3 — issue #759: `WORKERMAN_CACHE_WARMUP_TIMEOUT` no-op on the Runner path

Branch: `fix/issue-759-workerman-cache-warmup-timeout-is-a-no-o`, diff vs round 2 (`74066e3..HEAD`).
Round-3 delta: `src/CacheWarmupTimeoutConfig.php` (extract `readEnvRaw()`),
`src/WorkermanBundle.php` (call it), `tests/DependencyInjection/WorkermanBundleIntegrationTest.php`
(N-2 `unset`-on-null restore). Plus proof-of-work notes (`code-decision-3.md`, `findings-coder.md`
round-3 section, `findings-review.md` N-1/N-2 entries, `review-2.md` — carried in the same commit).

## 1. docs/helpers/ — tag index + entries read

Tag index loaded from both files; tags matching the diff: `env`, `runner`,
`config-cache`, `config`, `tests`, `policy`, `changelog`, `coverage`.
Entries read: **FAQ-036** (config-cache,permissions,env,runner), **FAQ-035**
(config,tests,symfony-config — considered, ruled out: no `min()`/`max()` bound
changed), **DEC-016** (security,policy,config-cache,env), **DEC-007**
(coverage,ci,policy), **DEC-014** (memory,long-running,http,tests), **DEC-021**
(docs,upgrade,changelog).

**Violations of documented decisions: none.**
- FAQ-036 still prescribes this shape (lazy env read over
  `$_SERVER`/`$_ENV`/`getenv()` with `set()` as override); the helper keeps it,
  just in one place.
- DEC-016 (env-over-YAML for pre-boot paths; strict default) unchanged:
  invalid values still throw fail-fast on both paths.
- DEC-007: no threshold change. DEC-014: `reset()` affordance kept; not a keyed
  cache, no eviction pillar applies.
- DEC-021: CHANGELOG `[Unreleased]` still empty — F-6, maintainer call.

## 2. Earlier findings (F-1..F-7, N-1, N-2) — round-3 status

Detail with evidence in `findings-review.md` (appended, nothing deleted):

- F-1 (medium) — **fixed, still fixed.** Precedence now lives in exactly one
  place (`readEnvRaw()`); `loadExtension()` cannot drift from `resolve()` by
  construction. Repro: `$_SERVER=''`, `$_ENV='55'` → `readEnvRaw()='55'`,
  `resolve()=55`.
- F-2 (medium) — **fixed, still fixed.** No test-harness change this round;
  exported-var repro (`WORKERMAN_CACHE_WARMUP_TIMEOUT=77 ... --filter
  testResolveCacheWarmupTimeoutDefaultsWhenHolderEmpty`) passes.
- F-3 (low) — **fixed, still fixed.** Precedence/getenv tests untouched and
  passing (88-test subset green).
- F-4 (low) — **still present, deliberate.** `(int)` truncation unchanged, now
  via the shared helper on both paths (`"45.9"`→`45`, repro-confirmed); still
  pinned by `testLoadExtensionFloatEnvOverrideIsTruncated`.
- F-5 (low) — **fixed, still fixed.** Whitespace-only → `readEnvRaw()=null` /
  `resolve()=30` (repro-confirmed); trim lives inside the helper so both
  callers inherit it.
- F-6 (nit) — **still present.** `CHANGELOG.md:8` `[Unreleased]` still empty.
  Maintainer call (DEC-021).
- F-7 (nit) — **fixed, still fixed.** `is_scalar()` guard now lives inside
  `readEnvRaw()`; both callers inherit it. Repro: array value → `null` / `30`.
- N-1 (low) — **fixed.** The duplicated three-channel bridge is extracted to
  `CacheWarmupTimeoutConfig::readEnvRaw(): ?string`; `resolve()` and
  `loadExtension()` both call it. Behavior verified identical (F-1/F-5/F-7
  repros above); cast semantics untouched (F-4 parity).
- N-2 (nit) — **fixed.** `testLoadExtensionDoesNotMutateServerSuperglobal`
  now `unset()`s the `$_SERVER` key on restore when the saved value was absent
  (`WorkermanBundleIntegrationTest.php:146-150`).

## 3. New issues — round-3 change (`readEnvRaw()` shared helper)

**No new actionable findings.** Specifically checked and cleared:

- **Visibility / API surface:** `readEnvRaw()` is `public static` with
  `@internal Shared with WorkermanBundle; not part of the public API.` Public
  is by necessity — PHP has no friend/package-private visibility and
  `WorkermanBundle` is a different class, so a shared helper must be public to
  be callable. This matches existing precedent in the same files (`reset()` is
  `public` + `@internal Test affordance only` on both holders; DEC-014
  established the `@internal` utility-class pattern). No public-API snapshot /
  baseline exists in the repo to update; `final` class bars overriding. No
  action.
- **Behavioral identity of the extraction:** the helper returns the *trimmed*
  string-or-null, and both call sites cast `(int)` on exactly what they
  trimmed before — `resolve()` did `(int) trim((string) $raw)`, now
  `(int) $trimmed`; `loadExtension()` did `(int) trim((string) $envOverride)`,
  now `(int) $envOverride` (already trimmed). `php -r` repros confirm
  identical outcomes on all four probes (F-1/F-4/F-5/F-7). No action.
- **Sibling consistency:** `ConfigCacheGuardConfig::resolve()` keeps its own
  inline `=== ''` bridge (no trim / no `is_scalar`), but it resolves a bool
  fail-closed, not an int — out of scope, not a regression. No action.

## 4. Checks run (no commits)

- `vendor/bin/phpunit tests/CacheWarmupTimeoutConfigTest.php
  tests/DependencyInjection/WorkermanBundleIntegrationTest.php
  tests/ServerManagerTest.php` — OK (88 tests, 183 assertions, 1 skipped;
  skip + warning are the pre-existing XDEBUG-coverage notice)
- `vendor/bin/phpunit tests/RuntimeTest.php` — OK (9 tests, 13 assertions)
- `WORKERMAN_CACHE_WARMUP_TIMEOUT=77 vendor/bin/phpunit
  tests/ServerManagerTest.php --filter
  testResolveCacheWarmupTimeoutDefaultsWhenHolderEmpty` — OK (F-2 repro,
  still passes)
- `php -r` probes vs `readEnvRaw()`/`resolve()`: `''`+`'55'` → `'55'`/`55`;
  whitespace-only → `null`/`30`; array value → `null`/`30`; `"45.9"` → `45`
- `vendor/bin/phpstan analyse` — no errors
- `vendor/bin/php-cs-fixer fix --dry-run` — clean (0 of 259 fixable)

## 5. Docs-helper candidates proposed (not written — retro owns `docs/helpers/`)

Unchanged from round 2 (carried over, not re-proposed in full):
1. Append resolution note to FAQ-036 (lazy `resolve()` landed; `set()` wins
   post-boot).
2. New FAQ entry — `??` does not skip `''` across the three env channels
   (F-1 specimen, now fixed and test-pinned; per README rule 4 the precedence
   tests ARE the gate — prefer skipping/shrinking).
