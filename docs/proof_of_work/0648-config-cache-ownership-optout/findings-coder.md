# findings-coder — issue #648: config-cache ownership guard opt-out

## Obstacles and surprises

1. **The `CacheWarmupTimeoutConfig` env bridge does not reach the Runner path.**
   `Runtime::getRunner()` (`src/Runtime.php:16`) calls
   `CacheWarmupTimeoutConfig::resolve()` **before** the kernel boots, when
   the static holder is still null (it is only `set()` in
   `WorkermanBundle::loadExtension()`), so it always returns `DEFAULT`; the
   env override effectively only works for `ServerManager`
   (`src/ServerManager.php:221`). In `Runner::run()` the warm-up fork
   (line 89) boots the kernel, but the parent already captured the
   pre-boot value. Net effect: `WORKERMAN_CACHE_WARMUP_TIMEOUT` is a no-op
   on the `Runner` path. Suggested fix: `Runner`'s constructor default
   (or `getCacheWarmupTimeout()`, `src/Runner.php:98`) should read the env
   var itself, or the holder should resolve lazily from `$_SERVER`/`$_ENV`
   like the new `ConfigCacheGuardConfig` does. Same class of problem I hit
   in #648 — which is why the new holder reads env lazily instead of
   copying the bridge pattern verbatim.

2. **Doc claims that are now conditional** (fixed as part of the change):
   `docs/security.md` said "A mismatch is refused … with a `RuntimeException`
   naming both UIDs" unconditionally (~line 463) and the "Container image
   builds" bullet called the ownership check "a hard boot failure, not a
   warning". Both now point at the new opt-out subsection. `FAQ-005`
   ("Warm-as-root cache trips the ownership guard at boot") makes the same
   absolute claim and is KB-owned (only the main session writes) — see the
   KB proposal below.

3. **`checkCacheFilePermissions()` is public-static on a final class** — an
   API surface that shipped for testability (511-524 of the old test file).
   Adding the 8th param with a default is BC-safe for callers; tests that
   pass 7 args keep working untouched (verified: no existing test needed
   modification).

4. **PHPUnit + PHP 8.5**: none — the suite runs clean. PHPStan is the
   strictest gate; the new code avoids `mixed` arithmetic (the env parse
   casts to string before `strtolower`).

## Bugs / weak spots noticed (in and out of scope)

| File:line | What | Suggested fix |
|---|---|---|
| `src/Runner.php:98` + `src/Runtime.php:16` | `WORKERMAN_CACHE_WARMUP_TIMEOUT` never reaches the Runner path (see obstacle 1) | Lazy env resolution in the holder or Runner |
| `src/ConfigLoader.php:146` (`validateCacheFilePermissions`) | `posix_getgroups()` is re-queried on every load; trivial cost at boot, but it is a syscall per cache load | None needed — informational |
| `docs/security.md` "Permission Validation" bullets | Each refusal bullet says "loading is refused" without repeating the opt-out caveat | Deliberately left verbatim (strict path description); the new subsection disclaims; accepted |
| `tests/ConfigLoaderTest.php` (pre-existing) | Root-only tests (`chown`/`chgrp` to foreign uid/gid) silently skip on non-root CI — the ownership and foreign-group integration paths are never exercised in CI | Consider a CI job with `sudo` or a `markTestSkipped` audit; out of scope |

## Knowledge-base candidates (propose; main session decides)

1. **FAQ:** "An env-var bridge read only in `loadExtension()` never reaches
   consumers constructed before kernel boot — resolve lazily instead"
   — `id=FAQ-036`, tags=config-cache,permissions,env,runner,
   trigger="adding an env-var bridge for a class constructed outside DI,
   or touching CacheWarmupTimeoutConfig / ConfigCacheGuardConfig".
   Paragraph: `CacheWarmupTimeoutConfig` is set from
   `WorkermanBundle::loadExtension()`, but `Runner` constructs its
   `ConfigLoader` and validates the config cache in the launcher main
   process **before** any kernel boot, so a loadExtension-only bridge is
   invisible there (the warm-up fork's `set()` does not propagate back to
   the parent; `Runtime::getRunner()` runs pre-boot too). The #648 opt-out
   (`ConfigCacheGuardConfig`) therefore resolves `$_SERVER`/`$_ENV`
   lazily on every call, with `set()` as an override; a bridge that must
   work on every construction path should do the same. This also explains
   why `WORKERMAN_CACHE_WARMUP_TIMEOUT` appears inert on the Runner path.

2. **FAQ update:** amend FAQ-005 (warm-as-root) to mention the opt-out
   exists since #648 (`WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE`), while noting
   it is a documented security downgrade — otherwise the FAQ's "hard boot
   RuntimeException, not a warning" claim becomes misleading.

3. **DEC:** "Security-guard opt-outs live in the environment, not the
   config tree" — tags=security,policy,config-cache,
   trigger="adding or removing a security opt-out, or a config node that
   loosens hardening". Paragraph: per DEC-006, hardening must not be
   loosened without an explicit, documented reason; when a legitimate
   opt-out exists (e.g. #648's trusted-cache-dir downgrade), it should be
   an env var rather than a YAML node, because (a) the loading path that
   trips the guard can run before the kernel (and its config tree) exists,
   (b) YAML may itself be baked into an image built by another user, and
   (c) env vars are visible in container specs and compose files, keeping
   the downgrade explicit. The strict default stays, and every degraded
   check keeps emitting the warning so the downgrade is never silent.
