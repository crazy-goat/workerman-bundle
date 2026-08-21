# code-decision-1 — issue #648: explicit opt-out for the config-cache ownership guard

## What I did

1. **New `src/ConfigCacheGuardConfig.php`** — a static holder following the
   `CacheWarmupTimeoutConfig` pattern, with `ENV_VAR =
   'WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE'`, `set()`/`get()`/`reset()` and
   `resolve(): bool`.
2. **`ConfigLoader::checkCacheFilePermissions()`** gains an 8th parameter
   `bool $trustCacheDir = false` (default = strict, so every existing call
   site and test keeps its behaviour unchanged). When true, all **four**
   refusal branches degrade to the advisory `warn` path.
3. **`ConfigLoader::validateCacheFilePermissions()`** passes
   `ConfigCacheGuardConfig::resolve()` into the pure function; the existing
   warn plumbing (PSR-3 `warning` or `E_USER_WARNING`) then handles the
   downgraded messages unchanged.
4. A small private `ConfigLoader::verdict()` helper routes each refusal
   message through either the strict `error` branch or the downgraded
   `warn` branch. The strict messages are byte-identical to before — the
   requirement that the strict-path error texts (both UIDs, chown
   suggestion) are preserved verbatim holds by construction, because the
   same string is reused.
5. Downgraded warnings carry a prefix naming the env var, so operations can
   tell a "guard downgraded" warning apart from the fail-open
   unreadable-metadata warning, and so the downgrade is visible in boot
   logs (the DEC-006 "explicit, documented reason" requirement made
   operational).
6. Docs: `docs/security.md` "Guard downgrade (explicit opt-out)" subsection
   + cross-references in "When this matters" and "Remediation"; CHANGELOG
   entry under [Unreleased] `### Changed`.
7. Tests: `tests/ConfigCacheGuardConfigTest.php` (new) and four pure +
   two integration tests in `tests/ConfigLoaderTest.php`, including the
   env-value parsing matrix and a strictness pin via the untouched existing
   tests.

## What I rejected and why

### Config key instead of env var

Rejected. The opt-out must work in warm-as-root scenarios where the YAML
config itself may live in an image built by another user — but more
decisively, the failing check happens in `Runner::createConfigLoader()`
(`src/Runner.php:55-63`), which construct the `ConfigLoader` **directly,
outside DI**, in the launcher main process. A Symfony config tree node
would only be visible to the DI-constructed loader and would never reach
that path. The env var is also the only mechanism that works for PHAR/BIN
builds without plumbing a parameter through the compiled container.

### WorkermanBundle-only env bridge (the CacheWarmupTimeoutConfig pattern verbatim)

Rejected with justification: `WorkermanBundle::loadExtension()` reads the
env var and `set()`s the holder. That works for the DI path and for
`ServerManager`, but **not** for the Runner path. In
`Runner::run()` (`src/Runner.php:33-41`) the launcher creates its own
`ConfigLoader` and calls `getWorkermanConfig()` → `loadFromCache()` →
`validateCacheFilePermissions()` in the **main process, before the kernel
boots**. The only kernel boot in that flow happens in the forked warmup
child (line 89), and static state set there does not propagate back to the
parent. So `ConfigCacheGuardConfig::resolve()` reads `$_SERVER`/`$_ENV`
**lazily on every call** (with `set()` as an override/test affordance),
which is strictly more robust than the `CacheWarmupTimeoutConfig` bridge
and covers every consumer.

### Downgrading only the ownership branch

The issue allowed "the ownership branch (and optionally the
directory-permission branches)". I downgrade **all four refusal branches**:
the opt-out is named "trust the cache directory", and all four signals
(world-writable dir, foreign-group-writable dir, foreign owner, world-
writable file) are symptoms of the same root condition — the cache
directory is not exclusively controlled by the runtime user. A deployment
that must accept a root-owned cache (warm-as-root) also has, by
construction, a directory layout it cannot fully remediate; picking a
subset would produce the same "refused at boot" failure the opt-out exists
to remove, just for a different branch. The unreadable-metadata branch was
already a warn (fail-open), so it stays untouched.

## What I was unsure about

- **Env value semantics**: I accept any value except empty/`0`/`false`/
  `no`/`off` (case-insensitive, trimmed) as truthy. An alternative is
  requiring exactly `1`, which is more rigid but more predictable. I chose
  the forgiving parse because `WORKERMAN_*` env vars in containers often
  carry `true`/`yes` from compose files, and the docs list the falsy
  values explicitly.
- **Warning message shape**: prefixing the original strict message with a
  downgrade marker duplicates text in the log. I considered emitting the
  original message verbatim as a warning instead — rejected because ops
  could not then distinguish "guard downgraded" warnings from the
  unreadable-metadata fail-open warning, and because the downgrade must be
  visible per DEC-006.
- **`get()` on the holder**: `CacheWarmupTimeoutConfig` has it, so I kept
  it for symmetry; the feature itself only needs `resolve()`.
- **Whether `resolve()` should cache the env read**: I deliberately do
  **not** cache it (unlike a boot-time bridge); the env cannot change
  mid-process anyway, and re-reading keeps test isolation trivial.
