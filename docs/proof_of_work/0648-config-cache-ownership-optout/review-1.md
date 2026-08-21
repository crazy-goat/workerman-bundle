# review-1 — issue #648: explicit opt-out for the config-cache permission guard

Review round: 1 (mandatory critical review — security-relevant diff: an opt-out that
downgrades the config-cache permission guard).
Branch: `feat/issue-648-add-an-explicit-opt-out-for-the-config-c`
Diff reviewed: `git diff origin/master..HEAD` (113b2b8, 640 insertions / 20 deletions).

## Executive verdict

The change is **sound and ready for disposition**. The opt-out is env-gated, defaults to
strict, keeps the strict-path error messages byte-identical, downgrades to the existing
advisory warning plumbing (never silent), and is documented as a security deviation with a
DEC-006 compliance narrative. All lint gates pass and the full test suite is green.

One **medium** security-design finding (fail-open env parsing: any non-blacklisted value
enables the downgrade) is the only substantive item I would require the main session to
consider before merge; everything else is low/nit. Recommendation: switch `resolve()` to
an allowlist (fail-closed) or add explicit acceptance tests for the fail-open semantics
and keep the docs in lock-step.

## Gates run

| Gate | Result |
|---|---|
| PHPStan level 8 (`src`, `tests`, `benchmarks`, `bin`) | OK, no errors |
| php-cs-fixer `--dry-run` | OK, 0 of 252 files |
| rector `--dry-run` | OK |
| `bin/kb-lint.php` | OK (1 pre-existing warning: faq.md line budget 350/300 — unrelated to this diff) |
| `bin/check-changelog.php` | OK (new entry structurally valid) |
| PHPUnit (php 8.5.9, no coverage driver local) | OK — 2290 tests, 16849 assertions, 32 skipped; 0 failures. New classes: `ConfigCacheGuardConfigTest` 15/15 pass; `ConfigLoaderTest` 42 tests, 3 skipped (root-only chgrp/chown, incl. the pre-existing patterns) |

Coverage: no xdebug/pcov locally, so the 80% line-coverage floor (`coverage:check`) could
not be executed here. Manual line-trace of every new/added line: all are reachable from
the new tests (see "Coverage-gate viability" below); CI must confirm the number.

## Procedure compliance

1. **docs/helpers tag index**: read faq.md index (matching tags `config-cache` → FAQ-005,
   `config` → FAQ-024/035, `permissions` → FAQ-005, `security` → FAQ-027, `coverage` →
   FAQ-010/011, `docs` → FAQ-019) and decisions.md index (`security` → DEC-005/006/010/013/015,
   `policy` → DEC-006–009, `coverage` → DEC-007, `docs` → DEC-012). Read the relevant
   entries: **DEC-006** ("Do not loosen these without an explicit, documented reason"),
   **DEC-007** (80% coverage floor, single source of truth), **DEC-009** (main session is
   the only KB writer), **DEC-012**, plus **FAQ-005** (warm-as-root trips the guard).
   No violation of a documented decision by the diff itself (see DEC-006 compliance below).
   No writes performed; candidate entries listed at the end.
2. **docs/security.md** "Config Cache File Protection" read fully (lines 409–617); the new
   "Guard downgrade (explicit opt-out)" subsection and the two cross-references are
   accurate w.r.t. implementation (verified claim-by-claim below).
3. **findings-coder.md** read; every claim verified (see verdicts) — this file becomes
   `findings-review.md` section A.
4. Full review dimensions: type correctness, error handling, PSR-12, tests/coverage,
   docs/CHANGELOG/README, security. All covered below.

## Strict path byte-identity (required evidence)

The four refusal messages and the unreadable-metadata warn message are byte-identical
between `origin/master:src/ConfigLoader.php` and HEAD:

- Verified mechanically: tokenizer comparison of every quoted-string chain in both files
  → all five messages match exactly (`IDENTICAL: true` for each; the only string present
  in HEAD and absent in master is the new downgrade prefix
  `The config-cache permission guard is explicitly downgraded (%s is set) ...`).
- Verified via the diff: no changed line (`+`/`-`) in `src/ConfigLoader.php` contains any
  message text — the message bodies appear only as context lines; the diff moves the
  `['warn' => null, 'error' => sprintf(...)]` wrapper into `self::verdict(...)` and
  reuses the same already-built string.
- The thrown type (`\RuntimeException`), the throw site (unchanged), the throw/warn
  evaluation order (error branch checked first), and the warn plumbing (PSR-3 `warning`
  with `['path' => $cachePath]` context, or `trigger_error(..., E_USER_WARNING)`) are all
  untouched.

Ownership message, byte-for-byte (both versions identical):

```
master: 'The configuration cache file "%s" is owned by uid %d, not by the current process user '
        . '(uid %d). The file may have been replaced by another user. Ensure the cache is written '
        . 'by the same user that loads it (e.g., warm up with the runtime user, or chown the cache '
        . 'to that user).'
HEAD:   identical (same four concatenated segments; tokenizer comparison: IDENTICAL: true)
```

Rendered: `The configuration cache file "/app/var/cache/prod/workerman/config.cache.php" is owned by uid 0, not by the current process user (uid 33). The file may have been replaced by another user. Ensure the cache is written by the same user that loads it (e.g., warm up with the runtime user, or chown the cache to that user).`

Same byte-identity result for: world-writable dir, foreign-group dir, world-writable file,
and the fail-open metadata warn message.

## Verification of findings-coder.md claims

| # | Coder claim | Verdict | Evidence |
|---|---|---|---|
| 1 | `WORKERMAN_CACHE_WARMUP_TIMEOUT` is a no-op on the Runner path (Runtime.php:16 / Runner.php:98) | **REAL** (scope: the Symfony-Runtime-reached Runner; works via ServerManager) | `WorkermanBundle::loadExtension()` (src/WorkermanBundle.php:101-106) is the only production `set()` site and runs during kernel boot; `Runtime::getRunner()` (Runtime.php:16) constructs `Runner` pre-boot → holder null → `resolve()` = DEFAULT. Constructor default pins the constant (Runner.php:21); the warmup wait loop uses the captured value (Runner.php:98, getter at :301). The fork at Runner.php:86-96 boots the kernel in the child only; child state never propagates to the parent. ServerManager resolves post-boot (ServerManager.php:39/82 → :219-222) so the console path works. Claim accurately says "only works for ServerManager". |
| 2 | security.md made absolute claims ("hard boot RuntimeException … not a warning"; ~line 463 and the "Container image builds" bullet); both fixed in this diff; FAQ-005 remains KB-owned | **REAL but incomplete** | Both security.md locations updated with links to Guard downgrade (verified in diff). FAQ-005 indeed still says "is a hard boot `RuntimeException` … not a warning" (faq.md:111-117) — correctly flagged as KB-owned. **Missed**: README.md:230-238 ("Config cache and runtime user") makes the same absolute claim and was not updated (see F3). |
| 3 | 8th param with default is BC-safe; no existing test needed modification | **REAL** | Diff removes zero test lines (only additions + `ConfigCacheGuardConfig::reset()` in tearDown); `checkCacheFilePermissions` has no other production callers; trailing-default param is BC-safe for positional and named args. Citation drift: the "shipped for testability" rationale lives in the ConfigLoader docblock, not old-test lines 511-524 (those show the fail-open warn tests) — substance unaffected. |
| 4 | PHPUnit/PHP 8.5 clean; PHPStan is the strictest gate | **REAL** | Full suite green locally (php 8.5.9); PHPStan level 8 clean; cs-fixer/rector clean. |
| B1 | posix_getgroups() re-queried per load (ConfigLoader.php:146) | **REAL, informational** | `validateCacheFilePermissions()` runs per `loadFromCache()` (boot-time only); line 146 in master, 158 in HEAD. Cost trivial; no action. |
| B2 | security.md refusal bullets keep "loading is refused" without caveat | Accepted | Deliberate; the strict-path wording remains the description of default behaviour; Guard downgrade subsection disclaims. Agree with the coder's "accepted". |
| B3 | Root-only tests silently skip on non-root CI | **REAL** | 3 skips in the two classes (1 pre-existing chgrp, 1 pre-existing chown, 1 new chown); pre-existing pattern (master had 7 `markTestSkipped` sites in this file). Out of scope; agreed. |

## Findings (my own; full entries in findings-review.md)

- **F1 (medium, security): fail-open env parsing** — `resolve()` treats *any* non-empty
  value that is not `0`/`false`/`no`/`off` as truthy (ConfigCacheGuardConfig.php:59-66).
  A typo (`=ture`, `=enabled`, `=1.0`, `=y`) or an "obvious" value not in the blacklist
  silently **enables** the downgrade. For a *guard*, the fail direction is wrong: unknown
  values should resolve strict (fail closed). The semantics are documented ("Any value
  other than … enables the downgrade"), so this is a design choice, not a bug — but the
  DEC-006 "explicit" bar argues for an allowlist (`1`, `true`, `on`, `yes`,
  case-insensitive, trimmed). Minimum bar: acceptance tests pinning the fail-open
  semantics explicitly (see F9/T-missing).
- **F2 (low): `getenv()` not consulted** — with `variables_order` lacking `E`/`S`
  (hardened/embedded setups), neither `$_SERVER` nor `$_ENV` carries the variable and the
  opt-out is silently inert. Direction is safe (inert ⇒ strict ⇒ visible refusal at boot),
  but docs/security.md "works in every mode that loads the cache" overstates. Cheap fix:
  `getenv(ENV_VAR)` as third fallback, e.g. `$_SERVER[...] ?? $_ENV[...] ?? getenv(...) ?: null`.
- **F3 (low, docs): README not updated** — README.md:230-238 still claims unconditionally
  "an ownership mismatch aborts `workerman:server start` with a `RuntimeException`, not a
  warning"; the opt-out caveat exists only in security.md. Add a one-line pointer (this is
  the 0.25.0 upgrade note, so edit conservatively).
- **F4 (low, tests): no-logger downgrade warning path untested** — the downgrade's most
  important operational signal (Runner/Runtime path has `logger: null` → `trigger_error`
  E_USER_WARNING) has no test; the precedent exists at tests/ConfigLoaderTest.php:488
  (set_error_handler capture). Missing mirrored test with `set(true)` + unsafe dir.
- **F5 (low, tests): env-driven end-to-end path untested** — both new integration tests
  drive the downgrade via the `ConfigCacheGuardConfig::set()` holder, never via the env
  var in `$_SERVER`; resolve()'s env-truthiness is unit-tested but the
  env → `validateCacheFilePermissions()` plumbing is not.
- **F6 (nit): `$_SERVER['']` shadows `$_ENV`** — `$_SERVER[X] ?? $_ENV[X]` — an empty
  string in `$_SERVER` suppresses a truthy `$_ENV` value. Same pattern as
  CacheWarmupTimeoutConfig:101-104, so consistent; document or accept.
- **F7 (nit): umask-000 fragility in the new integration tests** — both new integration
  tests create the cache dir with `mkdir(..., 0777)` and then assert exactly 1 warning;
  under `umask 000` the dir stays world-writable in the *foreign-owned* test too → branch 1
  fires as well → `assertCount(1)` fails. Latent, pre-existing fragility pattern; the new
  tests add instances.
- **F8 (nit): warning wording** — downgrade warning says "(%s is set)" where %s is the env
  var name; accurate in production (holder is test-only). Fine.
- **F9 (nit, tests): trust + safe file, and strict-message purity, unpinned** — no test
  asserts `['warn'=>null,'error'=>null]` when `trustCacheDir=true` and the file is safe,
  and no test asserts the strict error string does **not** contain the downgrade marker
  (a cheap byte-identity regression pin).

## Security review of the downgrade itself

1. **Is the downgrade too broad?** All four refusal branches degrade (coder's explicit
   decision, code-decision-1 §3). Defensible: all four signals are symptoms of "cache dir
   not exclusively controlled by runtime user"; a partial downgrade would just move the
   refusal to a sibling branch. The unreadable-metadata branch stays warn (unchanged).
   Approval subject to F1.
2. **Does the warning fire on every downgraded branch?** Yes — each refusal branch routes
   through `verdict()` which always returns a `warn` message (never silent-none), and the
   message embeds the original refusal text plus the env-var marker, so ops can
   distinguish "guard downgraded" from the fail-open metadata warning. Every degradation
   is visible at boot (DEC-006 "explicit, documented reason").
3. **Strict path unchanged** — byte-identical messages (evidence above), same exception
   type/site/order, same warn plumbing, default parameter keeps all existing callers
   strict. Verified the 7-arg pure-function call sites and the untouched refusal tests.
4. **Fail direction analysis** — env *presence* can only downgrade; env absence (or
   misconfiguration per F2) keeps strict. The dangerous direction (thinking strict while
   downgraded) is impossible via this mechanism. The only fail-open hazard is F1 (value
   typos) — hence the medium rating.
5. **DEC-006 compliance** — the diff does not loosen default behaviour; it adds an
   explicit, env-gated, documented, loudly-warning opt-out, and names DEC-006 in
   security.md. Compliant.
6. **Env-vs-config-tree placement** — correct: `Runner::createConfigLoader()` (Runner.php:55-63)
   constructs ConfigLoader outside DI before any kernel boot; YAML may be baked into images
   built by another user; env vars are visible in container specs. Code-decision-1's
   rejection of the config-key alternative is sound (verified the loading path).

## Coverage-gate viability (80% line floor)

Every new line in `ConfigCacheGuardConfig.php` is executed by the 12-case matrix
(15 tests) including both `??`-fallthrough orders, both truthy/falsy sets, trim +
case-fold, and the set-override branch. Every new line in `ConfigLoader.php` is executed:
`verdict()`'s warn branch by the 4 new pure tests + 2 integration tests; its strict branch
by the untouched refusal tests (7-arg calls → default false); `resolve()` propagation by
the integration tests. No new uncovered lines identified; the floor is not threatened.
CI (with a coverage driver) must confirm; I had no xdebug/pcov locally.

## What an automated check could have caught

- **PHPUnit `failOnWarning`**: a stray `trigger_error(E_USER_WARNING)` in a test that
  forgot to set the holder would surface as a warning-level failure — the reason the new
  integration tests all inject a logger. The corresponding *missing* test (F4) is the
  deliberate gap; no tool can require a test.
- **PHPStan level 8 / php-cs-fixer / rector / check-changelog / kb-lint**: all clean,
  nothing to catch.
- **Byte-identity**: no automated regression test exists (the strict tests pin messages
  via `expectExceptionMessage`); F9 proposes a cheap pin. This is what review is for.

## Candidate KB entries (proposed; main session decides — not written to docs/helpers/)

1. **FAQ-036** (coder's proposal, verified): "An env-var bridge read only in
   `loadExtension()` never reaches consumers constructed before kernel boot — resolve
   lazily instead." tags=config-cache,permissions,env,runner. The paragraph from
   findings-coder is accurate with one scoping note: the no-op affects the
   Symfony-Runtime path (Runtime.php:16), while the console/ServerManager path resolves
   post-boot and does honour the variable.
2. **FAQ-005 amendment** (coder's proposal): add "since #648 an explicit opt-out exists
   (`WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE`), a documented security downgrade" to the
   warm-as-root entry; otherwise its absolute "hard boot RuntimeException, not a warning"
   claim becomes misleading.
3. **DEC** (coder's proposal): "Security-guard opt-outs live in the environment, not the
   config tree" — tags=security,policy,config-cache. Endorsed; the pre-boot-loading
   rationale and the "every degraded check keeps emitting the warning" invariant both
   verified in code.
4. **DEC (new, this review)**: "Security-guard opt-out env parsing must fail closed"
   — id suggestion DEC-016, tags=security,policy,config-cache,env. One paragraph: for a
   *guard*, an unrecognised non-empty value must keep the strict default; the #648
   blacklist parse (`anything except 0/false/no/off`) turns typos like `=ture` into a
   silent downgrade, which fails the DEC-006 "explicit" bar; an allowlist
   (`1/true/on/yes`, case-insensitive, trimmed) makes mistakes fail loudly in the safe
   direction. Note the contrast with `WORKERMAN_CACHE_WARMUP_TIMEOUT`, which coerces
   permissively (`'3.7'` → 3) — that one configures a timeout, not a guard.
5. **FAQ (new, this review)**: "When a security guard gains an opt-out, every doc that
   states the strict behaviour needs the caveat" — id suggestion FAQ-037, tags=docs,
   security,config-cache. One paragraph: security.md was updated in #648, but the same
   absolute claim survives in README.md "Config cache and runtime user" and FAQ-005;
   audit all locations of a guard's strict-behaviour statement whenever a downgrade or
   opt-out is added, not just the canonical security document.
