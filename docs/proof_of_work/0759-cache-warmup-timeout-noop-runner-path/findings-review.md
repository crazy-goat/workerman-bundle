# findings-review — issue #759, review round 1

One entry per finding. Status `open` unless fixed in a later round.

## F-1 — `loadExtension()` empty-`$_SERVER` shadows `$_ENV` (precedence diverges from `resolve()`)
- **File:line:** `src/WorkermanBundle.php:92-96`
- **What is wrong:** line 92 merges with `$envOverride = $_SERVER[...] ?? $_ENV[...] ?? null`.
  `??` falls through only on `null`, not on `''`. When `$_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT']`
  is `''` and `$_ENV[...]` is `'55'`, line 92 yields `''`, the line-93 `=== ''` branch then
  overwrites it from `getenv()` (→ `null` when unset), so the `$_ENV` value is silently lost and
  the YAML value wins. `CacheWarmupTimeoutConfig::resolve()` uses sequential null-or-empty checks
  and would return `55` for the same environment — the two paths disagree, contradicting the
  "same precedence" claim in `code-decision-1.md`. Verified empirically with `php -r`
  (replica of lines 92-96 yields `NULL` where `resolve()` yields `55`).
- **Severity:** medium
- **Status:** fixed (round 2 — see note below)
- **Automatable check:** yes — a test pinning `loadExtension()` precedence
  (`$_SERVER=''`, `$_ENV='55'`, getenv unset → holder is `55`). No such test exists (see F-3).
  Propose writing it: one test per precedence layer, mirroring the `resolve()` provider tests.

## F-1 — round-2 re-check (2026-09-23): fixed
- Evidence: `src/WorkermanBundle.php:97-105` sequential `!is_scalar || trim()===''` checks;
  `php -r` repro (`$_SERVER=''`, `$_ENV='55'` → `55`); pinned by
  `tests/DependencyInjection/WorkermanBundleIntegrationTest.php:257`
  (`testLoadExtensionEmptyServerFallsThroughToEnv`).

## F-2 — `ServerManagerTest` timeout tests lack env isolation, now env-sensitive
- **File:line:** `tests/ServerManagerTest.php:91-96` (`testResolveCacheWarmupTimeoutDefaultsWhenHolderEmpty`)
- **What is wrong:** the coder hardened `CacheWarmupTimeoutConfigTest` and `RuntimeTest` with
  save/clear/restore of all three env channels (because `resolve()` now reads env lazily), but
  `ServerManagerTest::setUp/tearDown` still only calls `CacheWarmupTimeoutConfig::reset()`.
  With this diff, a stray `WORKERMAN_CACHE_WARMUP_TIMEOUT` in the CI/test environment flips
  `testResolveCacheWarmupTimeoutDefaultsWhenHolderEmpty` from DEFAULT to the env value.
  Reproduces via `WORKERMAN_CACHE_WARMUP_TIMEOUT=77 vendor/bin/phpunit tests/ServerManagerTest.php
  --filter testResolveCacheWarmupTimeoutDefaultsWhenHolderEmpty`.
- **Severity:** medium
- **Status:** fixed (round 2 — save/clear/restore added; exported-var repro passes)
- **Automatable check:** yes — the exported-var invocation above. Propose adding the same
  save/clear/restore block to `ServerManagerTest::setUp/tearDown`.

## F-3 — new `loadExtension()` getenv fallback has no behavioral test
- **File:line:** `src/WorkermanBundle.php:93-96`
- **What is wrong:** the `getenv()` fallback — half the stated motivation for touching the bundle
  (the getenv-only shadow gap in `findings-coder.md`) — is exercised by no test. There is no
  `WorkermanBundle` test class at all, and the 80% line-coverage gate (DEC-007) is global, so it
  cannot pin this branch. F-1 shipped undetected for exactly this reason.
- **Severity:** low
- **Status:** fixed (round 2 — `testLoadExtensionReadsGetenvOnlyOverride` + 3 precedence tests in `WorkermanBundleIntegrationTest.php:257-301`)
- **Automatable check:** partially — the F-1 precedence test would cover it. Propose writing
  `loadExtension()`-level tests (getenv-only override wins; empty-everywhere keeps YAML value).

## F-4 — `(int)` cast silently truncates; error message shows cast value, not raw input
- **File:line:** `src/CacheWarmupTimeoutConfig.php:75-82` (same pattern `src/WorkermanBundle.php:97`)
- **What is wrong:** `"45.9"` → `45`, `"60s"` → `60` silently, while the config-tree integer node
  would reject them; `"abc"` throws `got 0`, hiding the actual input from the operator. The coder
  deferred this deliberately for parity with the pre-existing cast (`findings-coder.md`, OUT of
  scope). Suggested fix (unchanged): strict-validate with `filter_var($raw, FILTER_VALIDATE_INT)`
  and include the raw string in the message.
- **Severity:** low
- **Status:** still present, deliberate (round 2 — repro `"45.9"`→`45` confirmed; now pinned by `testLoadExtensionFloatEnvOverrideIsTruncated`, so specified behavior)
- **Automatable check:** no — manual review only (behavioral choice, coder-documented).

## F-5 — whitespace-only env value throws instead of resolving to default
- **File:line:** `src/CacheWarmupTimeoutConfig.php:60-73`
- **What is wrong:** `" "` (spaces) is neither `null` nor `''`, so it reaches `(int)` → `0` → throw.
  `ConfigCacheGuardConfig::resolve()` trims before the emptiness check, so the sibling holder treats
  whitespace-only as absent. Minor inconsistency between the two holders that now share a docblock
  precedence claim.
- **Severity:** low
- **Status:** fixed (round 2 — `trim()` checks on both paths; repro `'   '`→`30`; pinned by `testResolveTreatsWhitespaceOnlyEnvVarAsAbsent` + `testLoadExtensionWhitespaceOnlyEnvOverrideFallsBackToConfig`)
- **Automatable check:** yes — a test (`$_SERVER[...] = ' '` → `DEFAULT`). Propose adding the case
  alongside `testResolveIgnoresEmptyEnvVar`.

## F-6 — no `CHANGELOG.md` `[Unreleased]` entry for the #759 fix
- **File:line:** `CHANGELOG.md:8`
- **What is wrong:** `[Unreleased]` is empty. Per DEC-021, removals/behavior changes are recorded
  under `[Unreleased]` (released entries immutable), and repo convention attaches an issue ref to
  every change. Whether a fix of this size warrants an entry is a maintainer call (as with the
  README scope note), but round 1 flags the absence.
- **Severity:** nit
- **Status:** still present (round 2 — `CHANGELOG.md:8` `[Unreleased]` still empty; maintainer call per DEC-021)
- **Automatable check:** no.

## F-7 — non-string superglobal values flow unguarded into `(int)`
- **File:line:** `src/CacheWarmupTimeoutConfig.php:60-75`, `src/WorkermanBundle.php:92-97`
- **What is wrong:** `$_SERVER`/`$_ENV` are `array<string, mixed>`; an array value (set
  programmatically) casts to `0`/`1` silently (`1` would pass validation as a 1-second timeout),
  and `int 0` / `bool false` throw rather than reading as absent. Pathological in practice
  (SAPIs deliver strings), PHPStan level 8 is clean, so nit only. Optional hardening: accept only
  `is_scalar()` values, treat the rest as absent.
- **Severity:** nit
- **Status:** fixed (round 2 — `is_scalar()` guards on both paths; repro array value→`30`)
- **Automatable check:** no (PHPStan already passes; would need a dedicated rule).

---

# Review round 2 (2026-09-23, diff `02b1d64..HEAD`)

Full detail in `review-2.md`. Per-finding verdicts: F-1 fixed, F-2 fixed,
F-3 fixed, F-4 still present (deliberate, now test-pinned), F-5 fixed,
F-6 still present (maintainer call), F-7 fixed. Checks: phpunit subsets OK
(88 + 9 tests), exported-var repro passes, phpstan clean, cs-fixer clean.
Helper-entry violations: none (FAQ-036, DEC-016, DEC-007, DEC-014, DEC-021
checked; FAQ-035 ruled out).

## N-1 — env-bridge logic duplicated between `resolve()` and `loadExtension()`
- **File:line:** `src/CacheWarmupTimeoutConfig.php:60-74`, `src/WorkermanBundle.php:97-105`
- **What is wrong:** the three-step sequential env read now exists twice and
  must evolve in lockstep — the two copies already drifted once (F-1).
  A shared `@internal` helper (e.g. `readEnvRaw(): mixed`) would remove the
  drift surface; optional, since `loadExtension()` falls back to YAML while
  `resolve()` falls back to `DEFAULT`, so they cannot share the whole method.
- **Severity:** low
- **Status:** open
- **Automatable check:** no — manual review only.

## N-2 — `testLoadExtensionDoesNotMutateServerSuperglobal` restores a null-valued key
- **File:line:** `tests/DependencyInjection/WorkermanBundleIntegrationTest.php:132-147`
- **What is wrong:** the test-local restore does
  `$_SERVER[...] = $savedServer` even when `$savedServer` is `null`, leaving
  a null-valued key until `tearDown()` cleans it. Harmless (tearDown restores
  the setUp snapshot), but `unset`-on-null would be tidier.
- **Severity:** nit
- **Status:** fixed (round 3 — `unset()`-on-null restore in `WorkermanBundleIntegrationTest.php:146-150`)
- **Automatable check:** no.

---

# Review round 3 (2026-09-23, diff `74066e3..HEAD`)

Full detail in `review-3.md`. Per-finding verdicts: F-1 fixed (still fixed —
precedence now single-sourced in `readEnvRaw()`; repro `''`+`'55'` → `55`),
F-2 fixed (still fixed; exported-var repro passes), F-3 fixed (still fixed),
F-4 still present (deliberate — `(int)` truncation now via shared helper,
`"45.9"`→`45` repro-confirmed, test-pinned), F-5 fixed (still fixed;
whitespace-only → `null`/`30`), F-6 still present (maintainer call —
`CHANGELOG.md:8` `[Unreleased]` still empty), F-7 fixed (still fixed —
`is_scalar()` guard lives in the helper, both callers inherit; array → `30`),
N-1 fixed (bridge extracted to `readEnvRaw(): ?string`, both paths call it),
N-2 fixed (`unset()`-on-null restore). New findings: none — `readEnvRaw()`
visibility (`public` + `@internal`) is public-by-necessity and matches the
`reset()` precedent; extraction verified behavior-identical on all four
probes. Checks: phpunit subsets OK (88 + 9 tests), exported-var repro passes,
phpstan clean, cs-fixer clean. Helper-entry violations: none (FAQ-036,
DEC-016, DEC-007, DEC-014, DEC-021 checked; FAQ-035 ruled out).
