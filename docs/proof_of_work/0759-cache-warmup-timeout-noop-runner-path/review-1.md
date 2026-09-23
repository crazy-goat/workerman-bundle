# review-1 — issue #759: `WORKERMAN_CACHE_WARMUP_TIMEOUT` no-op on the Runner path

Branch: `fix/issue-759-workerman-cache-warmup-timeout-is-a-no-o`, diff vs `origin/master`.
Changed files: `src/CacheWarmupTimeoutConfig.php`, `src/WorkermanBundle.php`,
`tests/CacheWarmupTimeoutConfigTest.php`, `tests/RuntimeTest.php`.

## 1. docs/helpers/ — tag index + entries read

Tags matching the diff: `env`, `runner`, `config-cache`, `config`, `tests`, `policy`.
Entries read: **FAQ-036** (config-cache,permissions,env,runner), **DEC-016** (security,policy,config-cache,env).
Considered and ruled out: FAQ-035 (no `min()`/`max()` bound changed), DEC-014 (static holder, not a
keyed cache — though the existing `reset()` affordance already satisfies its test-affordance pillar),
FAQ-039 (serialized PHPUnit runs — followed for the checks below).

**Violations of documented decisions: none.**
- FAQ-036 prescribes exactly this fix (lazy `resolve()` over `$_SERVER`/`$_ENV`/`getenv()` with
  `set()` as override, because `Runtime::getRunner()` runs pre-boot). The diff implements it.
- DEC-016 (env-over-YAML for pre-boot paths; fail-closed parsing; strict default) is honoured in
  spirit: invalid env values throw fail-fast on both paths instead of silently defaulting on one.

## 2. Earlier findings

`findings-review.md` did not exist (first round) — nothing to revisit. `findings-coder.md` and
`code-decision-1.md` were read; the coder's OUT-of-scope notes (raw-vs-cast message, `Runner`
constructor default, README/CHANGELOG) were independently re-examined below.

## 3. Review

**Correctness (core fix):** `resolve()` now reads the env lazily with `$_SERVER → $_ENV → getenv()`
precedence, `set()` wins post-boot, absent/empty → `DEFAULT`, non-positive → throw. This matches
`ConfigCacheGuardConfig::resolve()` and fixes the reported Runner-path no-op. `Runtime::getRunner()`
and `ServerManager::resolveCacheWarmupTimeout()` both go through `resolve()`, so both paths are fixed.

**Findings (detail in `findings-review.md`):**
- F-1 (medium) — `src/WorkermanBundle.php:92-96`: `??` chain does not fall through on `''`, so an
  empty `$_SERVER` value shadows `$_ENV` and precedence diverges from `resolve()`. Empirically verified.
- F-2 (medium) — `tests/ServerManagerTest.php:91-96`: timeout tests lack the env save/clear/restore
  the coder added to the other two test classes; the defaults assertion is now env-sensitive.
- F-3 (low) — `src/WorkermanBundle.php:93-96`: the new getenv fallback has no behavioral test
  (no `WorkermanBundle` test class exists); F-1 is the consequence.
- F-4 (low) — `src/CacheWarmupTimeoutConfig.php:75-82`: `(int)` truncation (`"45.9"`→45) and
  cast-value-in-message (`got 0` for `"abc"`); deferred deliberately, noted for the record.
- F-5 (low) — whitespace-only env value throws instead of defaulting (sibling guard trims).
- F-6 (nit) — empty `CHANGELOG.md` `[Unreleased]`, no entry for the fix (maintainer call).
- F-7 (nit) — non-string superglobal values flow into `(int)` unguarded; PHPStan clean.

**Type correctness (PHPStan level 8 mindset):** `vendor/bin/phpstan` passes with no errors.
`(int)` on `mixed` is permitted; `getenv()` `string|false` is normalized; `function_exists('getenv')`
guard matches the sibling holder. F-7 covers the residual array/int/bool edge.

**Error handling / edge cases:** invalid values throw `InvalidArgumentException` on both paths
(consistent fail-fast, no silent divergence) — good. Empty string → default on all three channels —
good. Precedence `$_SERVER > $_ENV > getenv()`, `set()` wins — good, except F-1's `''` hole in the
bundle bridge. Whitespace-only (F-5) and exotic casts (F-4) are the remaining edges.

**PSR-12:** `php-cs-fixer --dry-run` clean on all four changed files.

**Security (env input handling):** no injection/exec surface; unvalidated env input only becomes an
int after a `< 1` throw gate. No info leak in messages (raw value never echoed — which is also F-4's
debuggability complaint). No concerns.

**Docs:** README scope caveat (var documented as general) is now true in code — no doc change needed.
CHANGELOG absence flagged as F-6.

## 4. Checks run (serialized, per FAQ-039 — no commits)

- `vendor/bin/phpunit tests/ --filter 'CacheWarmupTimeout|Runtime'` — OK (61 tests, 87 assertions)
- `vendor/bin/phpunit tests/ServerManagerTest.php` — OK (43 tests, 103 assertions, 1 skipped)
- `vendor/bin/phpunit tests/RunnerTest.php --filter 'CacheWarmup|Timeout|timeout'` — OK (9 tests)
- `php-cs-fixer --dry-run` on the 4 changed files — clean
- `vendor/bin/phpstan analyse` — no errors
- `php -r` replica of `WorkermanBundle.php:92-96` — confirms F-1 (`NULL` where `resolve()` gives `55`)

(XDEBUG coverage warning only; the `OK, but there were issues!` suffix is the pre-existing
coverage-driver notice, not a failure.)

## 5. Docs-helper candidates proposed (not written — retro owns `docs/helpers/`)

1. **Append resolution note to FAQ-036** (as the coder proposed): lazy `resolve()` landed for
   `CacheWarmupTimeoutConfig`; `set()` wins post-boot. Suggested tags: keep
   `config-cache,permissions,env,runner`; trigger: keep existing.
2. **New FAQ entry — `??` does not skip `''` when bridging the three env channels.**
   Suggested tags: `env,config`; trigger: "merging `$_SERVER`/`$_ENV`/`getenv()` with
   empty-treated-as-absent". One paragraph: `$_SERVER[V] ?? $_ENV[V] ?? null` keeps an empty-string
   `$_SERVER` hit and never consults `$_ENV`; use sequential `=== null || === ''` checks like
   `CacheWarmupTimeoutConfig::resolve()` (F-1 is the specimen). If a precedence test lands per F-1,
   prefer the gate and skip/shrink this entry (README rule 4: prefer a gate over an entry).
