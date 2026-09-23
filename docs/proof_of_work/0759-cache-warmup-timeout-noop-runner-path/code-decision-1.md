# code-decision-1 — issue #759: WORKERMAN_CACHE_WARMUP_TIMEOUT is a no-op on the Runner path

## What I did

1. **`src/CacheWarmupTimeoutConfig.php` — `resolve()` now reads the env lazily.**
   When no explicit `set()` value is present, `resolve()` checks `$_SERVER`,
   then `$_ENV`, then `getenv()` (guarded by `function_exists()`, same as
   `ConfigCacheGuardConfig::resolve()`), on every call. Absent/empty resolves
   to `DEFAULT` (30); a present value is `(int)`-cast and a non-positive result
   throws `InvalidArgumentException`, consistent with `set()` and with the
   `Runner` constructor guard. An explicit `set()` (kernel boot via
   `WorkermanBundle::loadExtension()`, i.e. the YAML + env merge) still wins —
   so the post-boot `ServerManager` path is byte-for-byte unchanged in
   behaviour.
2. **`src/WorkermanBundle.php` — `loadExtension()` gains the `getenv()` fallback.**
   Without it, a deployment exposing the var only through `getenv()` (not
   `$_SERVER`/`$_ENV`) would have `loadExtension()` pin the holder to the YAML
   value and shadow the env var post-boot. Same `$_SERVER → $_ENV → getenv()`
   precedence as `resolve()`.
3. **Tests:** 9 new cases in `tests/CacheWarmupTimeoutConfigTest.php`
   (server/env/getenv sources, precedence order, set-wins, empty→default,
   non-positive rejection incl. non-numeric) + 1 in `tests/RuntimeTest.php`
   pinning the previously missing fresh-process path: env var → `Runtime::getRunner()`
   → `Runner::$cacheWarmupTimeout` with no prior `set()` call. Both test
   classes now save/clear/restore the var in `$_SERVER`/`$_ENV`/real env in
   `setUp()`/`tearDown()` so the new lazy read can neither leak out nor be
   polluted from outside (the old `testGetRunnerUsesDefaultTimeoutWhenHolderIsEmpty`
   would otherwise be order/env-dependent).

## What I rejected and why

### Runner-side env fallback (`Runner::__construct` default or `getCacheWarmupTimeout()` re-read)

Rejected as the primary fix. It would repair the Runner path but leave two
readers of the same var (`Runner` + `CacheWarmupTimeoutConfig`) with
diverging precedence/validation, and every future `resolve()` caller would
re-inherit the bug. Fixing the holder once fixes all current callers
(`Runtime::getRunner()`, `ServerManager::resolveCacheWarmupTimeout()`) and
any future ones.

### Caching the env value in a static after first read

Rejected. `resolve()` is called once per process on each production path, so
there is no performance case, and caching would break the "read freshly on
each call" contract the `RuntimeTest` suite pins (and would make tests
order-dependent). Matches `ConfigCacheGuardConfig::resolve()`, which also
reads on every call.

### Fail-open on invalid env (fall back to DEFAULT instead of throwing)

Rejected. `set()` throws on `< 1`, `Runner::__construct` throws on `< 1`, and
`loadExtension()` `(int)`-casts then `set()`s — so on the console path an
invalid value is already a loud boot error. Silently substituting 30 on the
Runner path would make the same misconfiguration fatal on one path and silent
on the other. Throwing keeps both paths consistent (fail-fast on operator
error).

### Changing `Runner`'s constructor default or signature

Not needed. `Runtime::getRunner()` already passes `resolve()` explicitly, and
the default (`CacheWarmupTimeoutConfig::DEFAULT`) stays as the no-env
fallback. Smallest diff = holder + one `getenv()` line in the bundle.

## Uncertainties

- `(int)` cast semantics for exotic values (`"45.9"` → 45, `"0x10"` → 0 →
  throw, `" 60 "` → 60): inherited verbatim from the pre-existing
  `loadExtension()` `(int)` cast, so no behaviour change versus the console
  path, but the tree's `min(1)` + integer node would reject `"45.9"` while the
  env path truncates it. Consistent within env handling; noted, not changed.
- `README.md:67,155` present the var as a general feature with no Runner-path
  caveat (flagged in the issue). Left untouched — docs change is for the
  maintainer/retro step to decide; the code now actually honours the claim.
