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
- **Status:** open
- **Automatable check:** yes — a test pinning `loadExtension()` precedence
  (`$_SERVER=''`, `$_ENV='55'`, getenv unset → holder is `55`). No such test exists (see F-3).
  Propose writing it: one test per precedence layer, mirroring the `resolve()` provider tests.

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
- **Status:** open
- **Automatable check:** yes — the exported-var invocation above. Propose adding the same
  save/clear/restore block to `ServerManagerTest::setUp/tearDown`.

## F-3 — new `loadExtension()` getenv fallback has no behavioral test
- **File:line:** `src/WorkermanBundle.php:93-96`
- **What is wrong:** the `getenv()` fallback — half the stated motivation for touching the bundle
  (the getenv-only shadow gap in `findings-coder.md`) — is exercised by no test. There is no
  `WorkermanBundle` test class at all, and the 80% line-coverage gate (DEC-007) is global, so it
  cannot pin this branch. F-1 shipped undetected for exactly this reason.
- **Severity:** low
- **Status:** open
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
- **Status:** open
- **Automatable check:** no — manual review only (behavioral choice, coder-documented).

## F-5 — whitespace-only env value throws instead of resolving to default
- **File:line:** `src/CacheWarmupTimeoutConfig.php:60-73`
- **What is wrong:** `" "` (spaces) is neither `null` nor `''`, so it reaches `(int)` → `0` → throw.
  `ConfigCacheGuardConfig::resolve()` trims before the emptiness check, so the sibling holder treats
  whitespace-only as absent. Minor inconsistency between the two holders that now share a docblock
  precedence claim.
- **Severity:** low
- **Status:** open
- **Automatable check:** yes — a test (`$_SERVER[...] = ' '` → `DEFAULT`). Propose adding the case
  alongside `testResolveIgnoresEmptyEnvVar`.

## F-6 — no `CHANGELOG.md` `[Unreleased]` entry for the #759 fix
- **File:line:** `CHANGELOG.md:8`
- **What is wrong:** `[Unreleased]` is empty. Per DEC-021, removals/behavior changes are recorded
  under `[Unreleased]` (released entries immutable), and repo convention attaches an issue ref to
  every change. Whether a fix of this size warrants an entry is a maintainer call (as with the
  README scope note), but round 1 flags the absence.
- **Severity:** nit
- **Status:** open
- **Automatable check:** no.

## F-7 — non-string superglobal values flow unguarded into `(int)`
- **File:line:** `src/CacheWarmupTimeoutConfig.php:60-75`, `src/WorkermanBundle.php:92-97`
- **What is wrong:** `$_SERVER`/`$_ENV` are `array<string, mixed>`; an array value (set
  programmatically) casts to `0`/`1` silently (`1` would pass validation as a 1-second timeout),
  and `int 0` / `bool false` throw rather than reading as absent. Pathological in practice
  (SAPIs deliver strings), PHPStan level 8 is clean, so nit only. Optional hardening: accept only
  `is_scalar()` values, treat the rest as absent.
- **Severity:** nit
- **Status:** open
- **Automatable check:** no (PHPStan already passes; would need a dedicated rule).
