# findings-coder — issue #759: WORKERMAN_CACHE_WARMUP_TIMEOUT no-op on the Runner path

## Obstacles

- **Pre-push lint is two tools, and the failure mode is sequential.** First run
  failed on `php-cs-fixer` (`concat_space`: repo wants spaces around `.` —
  `ENV_VAR . '=' . $x`, I wrote `ENV_VAR.'='.$x`). After fixing per-file, the
  second run failed on `phpstan` (`property.onlyWritten` on an unused backup
  property in the new test). Neither failure was in `src/`; both were in the
  new test scaffolding. Fixed by removing the dead property; `composer lint`
  is fully green now.
- **`php-cs-fixer fix <two files>` needs `--config` for multiple paths**
  (bare multi-path invocation errors with "For multiple paths config parameter
  is required"). Ran once per file with `--config=.php-cs-fixer.dist.php`.

## Surprises

- **The `getenv()`-only shadow gap in `loadExtension()`** (fixed in this diff):
  `loadExtension()` *always* calls `set()`, even with no env override, so once
  the kernel boots the lazy env read in `resolve()` becomes dead code. If the
  var were visible only via `getenv()`, the old two-source read in
  `loadExtension()` would pin the YAML value and shadow the env var on the
  post-boot path. The added `getenv()` fallback closes that.
- **Existing tests were env-fragile and I made them robust as a side effect:**
  `testGetRunnerUsesDefaultTimeoutWhenHolderIsEmpty` and
  `testResolveReturnsDefaultWhenHolderEmpty` asserted DEFAULT with an empty
  holder but never cleared the environment — with the fix, a stray
  `WORKERMAN_CACHE_WARMUP_TIMEOUT` in the CI/test environment would flip them.
  Both test classes now save/clear/restore all three env channels.

## Bugs / weak spots noticed (IN scope, handled)

- `src/CacheWarmupTimeoutConfig.php:41-44` (old): `resolve()` never read env —
  the reported bug. Fixed by lazy resolution.
- `src/WorkermanBundle.php:92` (old): two-source env read, no `getenv()`.
  Fixed (see above).

## Bugs / weak spots noticed (OUT of scope, not changed)

- `src/WorkermanBundle.php:94` (now ~line 98): `(int)` cast of the env override
  is unvalidated before `set()` — relies on `set()` throwing for `< 1`, which
  is correct but produces a boot-time `InvalidArgumentException` whose message
  shows the *cast* value (`got 0` for `"abc"`), not the raw string. Suggested
  fix: validate with `filter_var($raw, FILTER_VALIDATE_INT)` / explicit
  numeric check in `loadExtension()` and in `resolve()`, and include the raw
  value in the message. Deliberately kept `(int)` to stay behaviour-identical
  with the console path.
- `src/Runner.php:21`: constructor default `= CacheWarmupTimeoutConfig::DEFAULT`
  means `new Runner($factory)` without an explicit timeout silently ignores the
  env var — only `Runtime`/`ServerManager` pass `resolve()` explicitly. Any
  future direct `new Runner(...)` call site re-inherits a variant of #759.
  Suggested fix: default the parameter to `null` and `resolve()` inside the
  constructor (`$this->cacheWarmupTimeout = $cacheWarmupTimeout ??
  CacheWarmupTimeoutConfig::resolve()`), so the env works regardless of call
  site. Not done here to keep the diff minimal.
- `README.md:67,155`: documents the var as a general feature with no scope
  caveat (flagged in the issue body). Now true in code; no doc change made —
  maintainer call whether a CHANGELOG entry under `[Unreleased]` is wanted.
- `tests/RunnerTest.php::testCacheWarmupTimeoutDefaultsTo30` is a
  source-text structural test (`assertStringContainsString`), not a behavioural
  one — `new Runner($factory)` default behaviour is covered behaviourally only
  by `testGetCacheWarmupTimeoutDefaultsTo30Seconds`, which (like the old
  Runtime tests) does not clear the environment; a stray env var would now
  break it since the constructor default bypasses `resolve()`. Actually with
  the current design the constructor default does NOT read env, so that test
  is stable — but that stability is precisely the residual #759-shaped trap
  noted above for direct construction. Suggested fix: same constructor-null
  change, plus env save/clear in `RunnerTest::setUp()`.

## Docs-helper candidates proposed (not written — retro step owns docs/helpers/)

- `faq.md` tag `config-cache,permissions,env,runner` (FAQ-036) already predicts
  this exact bug ("why WORKERMAN_CACHE_WARMUP_TIMEOUT is inert on the Runner
  path — tracked as issue #759"). Proposed: append the resolution note to
  FAQ-036 (lazy `resolve()` landed; `set()` wins post-boot) rather than a new
  entry.
- `decisions.md` tag `env` (DEC-016): the "env var rather than YAML node
  because the path runs pre-boot" rationale now covers a second holder.
  Proposed: one-line cross-reference from DEC-016 to this fix.

## Round 2 — review round 1 fixes applied (code-decision-2.md)

Fixed (see code-decision-2.md for approach/rejected alternatives):

- **F-1 (medium):** `loadExtension()` now uses sequential null-or-blank
  checks mirroring `resolve()` — empty `$_SERVER` falls through to `$_ENV`
  then `getenv()`. Pinned by
  `WorkermanBundleIntegrationTest::testLoadExtensionEmptyServerFallsThroughToEnv`.
- **F-2 (medium):** `ServerManagerTest::setUp/tearDown` now
  save/clear/restore all three env channels. Verified with the review's
  automatable check: `WORKERMAN_CACHE_WARMUP_TIMEOUT=77 ... --filter
  testResolveCacheWarmupTimeoutDefaultsWhenHolderEmpty` passes.
- **F-3 (low):** `loadExtension()` bridge covered at extension level —
  `testLoadExtensionReadsGetenvOnlyOverride`,
  `testLoadExtensionEmptyServerFallsThroughToEnv`,
  `testLoadExtensionBlankServerFallsThroughToEnv`,
  `testLoadExtensionWhitespaceOnlyEnvOverrideFallsBackToConfig`.
  Correction to the review record: a `WorkermanBundle` test class was never
  missing — `WorkermanBundleIntegrationTest` already owned this bridge (it
  routes through `AbstractBundle` into `loadExtension()`); it just was not
  in the round-1 diff, so the reviewer missed it. No new test class added
  deliberately. Its `setUp/tearDown` now also isolates `getenv()`.
- **F-5 (low):** both bridges trim before the emptiness check
  (whitespace-only → absent, falls through per channel, else `DEFAULT`);
  matches the `ConfigCacheGuardConfig` sibling. Pinned by
  `testResolveTreatsWhitespaceOnlyEnvVarAsAbsent`,
  `testResolveFallsThroughBlankServerValueToEnvSuperglobal`,
  `testResolveTrimsPaddedEnvVar` plus the two integration tests above.
- **F-7 (nit):** hardened with the trivial, safe `is_scalar` guard —
  non-scalar superglobal values are treated as absent rather than cast, on
  both bridges. PHPStan level 8 still clean.

Deliberately not fixed:

- **F-4 (low):** `(int)` truncation (`"45.9"`→45) and cast-value-in-message
  left as-is for parity between the boot and Runner paths, per the
  reviewer's own deferral. Strict `filter_var()` validation would be a
  behavior change beyond #759 scope.
- **F-6 (nit):** `CHANGELOG.md` untouched — left for the main session
  (step 8).

## Obstacles (round 2)

- **The F-3 premise was half wrong — good news.** The review claimed "no
  `WorkermanBundle` test class exists", but
  `tests/DependencyInjection/WorkermanBundleIntegrationTest.php` already
  exercises `loadExtension()` through the real `AbstractBundle` extension
  wiring (config tree → extension → holder). So instead of scaffolding a
  new unit class around `ContainerConfigurator` mocks, the fix added four
  focused tests to the existing integration class — stronger gate, smaller
  diff. Its `tearDown` previously only unset `$_SERVER`/`$_ENV` without
  restoring prior values and never touched `getenv()`, so it also got the
  full save/clear/restore treatment (required before any `putenv()`-based
  test could land without leaking into sibling tests).
- **No new lint failure modes this round.** `php-cs-fixer --dry-run` clean
  on all five touched files on the first pass; `phpstan` clean. New tests
  use the `ENV_VAR . '=' . $x` concat-spacing the round-1 obstacle taught.

