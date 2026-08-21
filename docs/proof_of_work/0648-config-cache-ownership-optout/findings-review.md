# findings-review — round 1, issue #648

One entry per finding. `handled later` is filled by the main session when the disposition
is recorded. Section A: claims from findings-coder.md as verified (they become part of the
record). Section B: findings from this review.

## A. Coder claims (verification verdicts)

| # | file:line | What is wrong / claim | Severity | Handled later |
|---|---|---|---|---|
| C1 | src/Runtime.php:16 + src/Runner.php:98 | **REAL.** `WORKERMAN_CACHE_WARMUP_TIMEOUT` is a no-op on the Runner path reached via the Symfony Runtime: `Runtime::getRunner()` resolves pre-kernel-boot when the holder is null (only `WorkermanBundle::loadExtension()` sets it, during boot), so `resolve()` returns DEFAULT; the forked warmup child's `set()` never propagates to the parent; `Runner`'s constructor default pins the constant (Runner.php:21) and the wait loop uses the captured value (Runner.php:98). Works on the console/ServerManager path (ServerManager.php:39/82 resolve post-boot). Coder's own suggested fixes are valid options. | medium (pre-existing bug, out of scope) | |
| C2 | docs/security.md ~463, "Container image builds" bullet | **REAL, but incomplete.** Both security.md absolute claims ("refused … RuntimeException naming both UIDs"; "hard boot failure, not a warning") were made conditional and cross-linked to the new Guard downgrade subsection — verified in the diff. FAQ-005 (faq.md:111-117) keeps the absolute claim and is correctly flagged as KB-owned. **Missed by the coder: README.md:230-238** makes the same absolute claim and was not touched (see F3). | low (docs; one location missed) | |
| C3 | src/ConfigLoader.php:211-220 (8th param) | **REAL.** Adding `bool $trustCacheDir = false` as trailing param is BC-safe (positional and named args); zero removed test lines; no other production callers of `checkCacheFilePermissions`; the untouched 7-arg strict tests act as the strictness pin. Citation drift: the "public-static for testability" rationale is in the ConfigLoader docblock, not old-test lines 511-524 (those are the fail-open warn tests) — substance unaffected. | nit | |
| C4 | tests (suite health) | **REAL.** Full suite green locally on PHP 8.5.9 (2290 tests, 0 failures, 32 skips); PHPStan level 8 clean; cs-fixer/rector clean. | info | |
| B1 | src/ConfigLoader.php:146 (master; :158 HEAD) | **REAL, informational.** `posix_getgroups()` re-queried on every `loadFromCache()`; boot-time only, trivial cost. No action. | nit | |
| B2 | docs/security.md "Permission Validation" bullets | Accepted by design: bullets keep describing strict behaviour; the new subsection disclaims. Agreed, no action. | nit | |
| B3 | tests/ConfigLoaderTest.php root-only skips | **REAL.** 3 skips in the touched classes (1 pre-existing chgrp, 1 pre-existing chown, 1 new chown `:824`); pre-existing pattern (7 markskipped sites in master). Out of scope, agreed. | nit | |

## B. Findings from this review

| # | file:line | What is wrong | Severity | Handled later |
|---|---|---|---|---|
| F1 | src/ConfigCacheGuardConfig.php:59-66 | **Fail-open env parsing for a security guard.** Any non-empty value not in the blacklist `0/false/no/off` (case-insensitive, trimmed) enables the downgrade; a typo (`=ture`, `=enabled`, `=1.0`, `=y`) silently *unlocks the guard*. The semantics are documented, but for a guard the polarity should be fail-closed: allowlist `1/true/on/yes`, everything else → strict. Minimum bar: explicit tests pinning the current fail-open semantics (see F9) if the design is kept. | medium | **FIXED** — `resolve()` now uses a fail-closed allowlist (`1/true/on/yes`); unrecognised values (`banana`, `ture`, `enabled`, `1.0`, `yes please`…) resolve to strict; pinned in `testResolveTreatsUnrecognisedEnvValuesAsStrict`; docs/security.md updated to state the allowlist and fail-closed polarity. |
| F2 | src/ConfigCacheGuardConfig.php:53 | `resolve()` reads only `$_SERVER`/`$_ENV`; no `getenv()` fallback. With `variables_order` lacking `E`/`S` the opt-out is silently inert → strict refusal at boot (safe direction, but inoperative). docs/security.md "works in every mode that loads the cache" overstates. Suggested: `getenv(ENV_VAR)` as third fallback. | low | **FIXED** — `getenv()` added as third source (after `$_SERVER`, `$_ENV`); pinned in `testResolveFallsBackToGetenvWhenSuperglobalsAreEmpty`; docs/security.md now says `$_SERVER`/`$_ENV`/`getenv()`. |
| F3 | README.md:230-238 | README "Config cache and runtime user" still claims unconditionally "an ownership mismatch aborts `workerman:server start` with a `RuntimeException`, not a warning". security.md was fixed; README and FAQ-005 (KB-owned) were not. Add a one-line pointer to the Guard downgrade subsection. | low | **FIXED** — README gains a note under "Config cache and runtime user" pointing at `WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE` and the Guard downgrade subsection, warning it is a security downgrade. FAQ-005 is KB-owned; candidate amendment proposed to the main session (retro step). |
| F4 | tests/ConfigLoaderTest.php (new tests) | No test covers the **no-logger** downgrade path: with `logger: null` (Runner/Runtime path) a downgraded branch must raise `E_USER_WARNING` carrying the env-var marker. The precedent exists (set_error_handler capture, `testValidateCacheFilePermissionsTriggersWarningWhenMetadataUnreadableAndNoLogger`, tests/ConfigLoaderTest.php:488). Mirror it with `set(true)` + unsafe cache dir. | low | **FIXED** — `testLoadFromCacheTriggersEUserWarningWhenTrustSetAndNoLogger` added (set_error_handler capture, asserts `world-writable` + env-var marker in the message). |
| F5 | tests/ConfigLoaderTest.php (new integration tests) | Downgrade integration is exercised only through the `ConfigCacheGuardConfig::set()` holder; the env-driven plumbing (`$_SERVER[ENV_VAR] = '1'` → resolve() → load proceeds with warning) is not tested end-to-end. | low | **FIXED** — `testLoadFromCacheProceedsWithWarningForWorldWritableDirectoryWhenTrustSet` now drives the downgrade through `$_SERVER[ENV_VAR] = '1'` (unset in finally), covering env → resolve() → load-with-warning end to end. |
| F6 | src/ConfigCacheGuardConfig.php:53 | `$_SERVER[X] ?? $_ENV[X]` — an empty string in `$_SERVER` shadows a truthy `$_ENV` value. Same pattern as CacheWarmupTimeoutConfig:101-104 (consistent), but undocumented precedence. Accept or add a test pinning it. | nit | **FIXED** — empty `$_SERVER` value no longer shadows a truthy `$_ENV` value (empty strings skipped per source); pinned in `testResolveFallsBackToEnvWhenServerValueIsEmpty`. |
| F7 | tests/ConfigLoaderTest.php:750-796, :797-875 | Both new integration tests assert exactly 1 logged warning; under `umask 000` the foreign-owned test's cache dir stays world-writable and branch 1 fires alongside branch 3 → `assertCount(1)` would fail. Latent, pre-existing fragility pattern (mkdir 0777 + umask), new tests add instances. | nit | **FIXED, with caveat** — both chown-based tests (strict refusal :424 and foreign-owned trust :851) now pin the containing directory, so the assertion is deterministic. Round 2 established the umask-000 mechanism was a phantom (warmUp() pins umask 0077 internally, so the dir is 0700 under any outer umask): the pins are defense-in-depth, and the fix comments were corrected accordingly (see N3). |
| F8 | src/ConfigLoader.php:307-312 | Downgrade warning wording "(%s is set)" — accurate in production (holder is test-only); message duplicates refusal text by design (ops distinguishability). No change requested. | nit | Not a finding — no action (as the review itself stated). |
| F9 | tests/ConfigCacheGuardConfigTest.php, tests/ConfigLoaderTest.php | Unpinned corners: (a) `trustCacheDir=true` + safe file → `['warn'=>null,'error'=>null]` (no warning when nothing was degraded); (b) strict error string must NOT contain the downgrade marker (byte-identity regression pin); (c) if F1's fail-open parse is kept, explicit tests for unrecognised values (`banana`, `ture`, `1.0`) documenting the semantics. | nit | **FIXED** — (a) `testCheckCacheFilePermissionsWithTrustAcceptsSecurePermissions`; (b) `assertStringNotContainsString(ENV_VAR, error)` pin in `testCheckCacheFilePermissionsRefusesFileOwnedByAnotherUser`; (c) superseded by F1's allowlist — `testResolveTreatsUnrecognisedEnvValuesAsStrict` pins the now-fail-closed semantics. |

## Round 2 (review of commit 7124f3c; full narrative in review-2.md)

Per-finding verdicts on the round-1 dispositions (evidence read from the actual
code/tests; all gates re-run: PHPStan level 8 full-repo OK, PHPUnit full suite
2299/0/32, cs-fixer 0/252, rector OK, kb-lint OK, check-changelog OK):

- F1: **fixed** — `resolve()` (src/ConfigCacheGuardConfig.php:86-88) is a strict
  allowlist (`1/true/on/yes`, case- and whitespace-insensitive); typos (`ture`,
  `banana`, `enabled`, `1.0`, `yes please`, `ONN`) → false, pinned in
  `testResolveTreatsUnrecognisedEnvValuesAsStrict`; `0/false/no/off`/empty → false,
  pinned in `testResolveTreatsAbsentEmptyAndFalsyEnvValuesAsStrict`. Per-source
  empty-string skip (:71-78) reintroduces no shadowing gap; a non-empty `'0'` in
  `$_SERVER` shadowing truthy `$_ENV` is the documented first-non-empty precedence.
  Round-2 truthy set is a strict subset of round-1's — no regression direction.
- F2: **fixed** — `getenv()` third fallback (:75-78, `$env === false ? '' : $env`);
  pinned in `testResolveFallsBackToGetenvWhenSuperglobalsAreEmpty`; restore in finally
  is correct for both `false` (putenv without `=`) and string values; docs/security.md:585
  updated. Two side-effects recorded as NEW N1 (getenv-disabled crash vector) and N2
  (suite hermeticity w.r.t. an exported variable).
- F3: **fixed** — README.md:240-247 opt-out note with security-downgrade framing and a
  working `#guard-downgrade-explicit-opt-out` anchor; FAQ-005 (KB-owned, absolute claim
  intact) amendment re-proposed to the main session.
- F4: **fixed** — `testLoadFromCacheTriggersEUserWarningWhenTrustSetAndNoLogger`
  (tests/ConfigLoaderTest.php:873-915): world-writable dir + trust + no logger →
  `E_USER_WARNING` captured via set_error_handler; message asserted to contain
  `world-writable` and the ENV_VAR marker; restore in finally. Exactly what round 1
  asked; passes.
- F5: **fixed** — `testLoadFromCacheProceedsWithWarningForWorldWritableDirectoryWhenTrustSet`
  (:774-821) drives the downgrade exclusively via `$_SERVER[ENV_VAR]='1'` (no `set()`
  in that path); chain is the production one: getWorkermanConfig → loadFromCache →
  validateCacheFilePermissions → `resolve()` (ConfigLoader.php:87-98, :160-169;
  Runner.php:35-39); `finally` unsets the superglobal and resets the holder; flat
  `assertCount(1)` is umask-independent (dir chmod'd deterministically).
- F6: **fixed** — per-source empty-string skip pins empty `$_SERVER` no longer shadowing
  truthy `$_ENV` (`testResolveFallsBackToEnvWhenServerValueIsEmpty`).
- F7: **fixed, with caveat (see N3)** — both chown tests pin the dir (ConfigLoaderTest
  :424 and :851), satisfying the disposition literally; but the disposition says "all
  three chown-based tests" while exactly two exist, and the fix comments claim a
  world-writable warm-up dir under umask 000 that cannot occur (`warmUp()` pins
  `umask(0077)`; Filesystem::mkdir 0777 & ~0077 = 0700 — empirically verified). Round-1
  F7 was likely a phantom alarm; pins harmless but redundant.
- F8: not a finding (no action) — as round 1 stated.
- F9: **fixed** — (a) `testCheckCacheFilePermissionsWithTrustAcceptsSecurePermissions`
  (:756-772) asserts both null; (b) `assertStringNotContainsString(ENV_VAR, error)`
  strict-purity pin (:646); (c) superseded by F1's allowlist pins.
- C1–C4, B1–B3: unchanged by the round-2 delta — round-1 verdicts stand as recorded
  (C1 pre-existing out-of-scope, C2 README part now closed via F3, C3/C4/B1/B2/B3 as
  recorded).

### NEW findings (round 2)

| # | file:line | What is wrong | Severity | Handled later |
|---|---|---|---|---|
| N1 | src/ConfigCacheGuardConfig.php:76 | New boot-critical `getenv()` call: with `getenv` in `disable_functions` (hardened setups), `resolve()` fatals `Error: Call to undefined function getenv()` at boot even in strict mode (demonstrated with `php -d disable_functions=getenv`). Fail-loud but confusing; fix: `function_exists('getenv')` guard. | low | **FIXED** — getenv() call wrapped in `function_exists('getenv')` (src/ConfigCacheGuardConfig.php:80-83); resolve() degrades to '' when the fallback is unavailable, strict mode intact. |
| N2 | tests/ConfigCacheGuardConfigTest.php:12-22, tests/ConfigLoaderTest.php:23-27 + src/ConfigCacheGuardConfig.php:76 | Suite hermeticity: setUp/tearDown clear superglobals + holder but never the process env; the getenv fallback makes a shell-exported `WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE=1` break `testResolveIsFalseByDefault`, `testResolveTreatsAbsentEmptyAndFalsyEnvValuesAsStrict`, and strict ConfigLoaderTest tests (demonstrated). Realistic when a developer tests the opt-out in the same shell as `composer test`. Fix: putenv-delete/restore in both setUp/tearDown pairs. | low | **FIXED** — both test classes capture the process env in setUp, delete it (`putenv(ENV_VAR)` without `=`), and restore the captured value in tearDown; superglobals unset as before. |
| N3 | tests/ConfigLoaderTest.php:421-423, :848-850 | F7 fix comments assert warm-up-created dirs would be world-writable under umask 000 — mechanically false (`warmUp()` pins umask 0077; Filesystem::mkdir 0777 → 0700; verified empirically). Round-1 F7 was likely a phantom; pins harmless but redundant; disposition also overcounts ("all three" vs two chown tests). Fix: correct/drop comments or pins. | nit | **FIXED** — both comments now state the true rationale (pin keeps the test independent of how the dir mode is established / assertCount determinism); pins kept as defense-in-depth. F7 disposition corrected below ("both" chown tests, `:424` and `:851`). |

## Round 3 (review of commit ee727f9; full narrative in review-3.md)

Per-finding verdicts on the round-2 dispositions (evidence read from the actual
code/tests and re-derived empirically; gates re-run: PHPStan level 8 full-repo OK,
cs-fixer 0/252, rector full OK, kb-lint OK, check-changelog OK, full suite green in
both env directions):

- N1: **fixed** — `function_exists('getenv')` guard at src/ConfigCacheGuardConfig.php:79
  inside the last-resort fallback block (:76-81); `php -d disable_functions=getenv`
  probe yields `resolve() === false` with exit 0 (no fatal), and the `$_SERVER`-driven
  downgrade still resolves true when getenv is disabled. Behavior on normal runtimes is
  bit-identical to round 2 (function_exists is true). Pre-existing unguarded
  `\getenv('GRPC_ENABLE_FORK_SUPPORT')` at src/Runner.php:207 is unchanged from
  origin/master — out of scope, recorded.
- N2: **fixed** — both test classes capture the process env in setUp
  (`function_exists('getenv') ? getenv(ENV_VAR) : false`), delete it
  (`putenv(ENV_VAR)` without `=`), restore in tearDown (delete when false,
  `putenv('X='.$old)` otherwise), and unset the superglobals in BOTH setUp and tearDown
  (ConfigCacheGuardConfigTest.php:12-39; ConfigLoaderTest.php:16-48). The latter also
  closes the pre-existing superglobal leak round 2 flagged in ConfigLoaderTest ("unset
  as before" understates it). Empirically: `WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE=1
  vendor/bin/phpunit tests/ConfigLoaderTest.php tests/ConfigCacheGuardConfigTest.php`
  → 54 tests/122 assertions/3 skipped/0 failures; identical without the var; full suite
  with the var exported green (2301 tests/16884 assertions/32 skipped/0 failures, under
  `-d phar.readonly=0` — this host's Homebrew PHP has phar.readonly=On, which fails the
  repo's own PharReadOnlyGuardTest identically with and without the exported var,
  unrelated to the branch). Mechanism demo: old-setUp behaviour (superglobals only)
  leaks the exported value (resolve()=true); new setUp deletes it (resolve()=false).
- N3: **fixed** — comments at tests/ConfigLoaderTest.php:442-445 and :870-873 state the
  true rationale (mechanism-independent pin / assertCount determinism); no false umask
  claim remains; F7 disposition wording corrected to "both" chown tests (:424/:851 in
  the 7124f3c state; pins at :446/:874 at HEAD, drift = the +18 setUp lines).

Edge cases examined and closed (proven, not asserted):

- Cross-class putenv contamination: impossible — each tearDown restores exactly what
  its own setUp captured; the invariant "process env after tearDown == value at suite
  start" holds for any class order; the only mid-test putenv restores in `finally`.
- `testResolveFallsBackToGetenvWhenSuperglobalsAreEmpty` remains non-vacuous: the body
  sets `putenv('=1')` itself after setUp's deletion, so `resolve() === true` is only
  reachable via the getenv fallback; setUp deletion makes the pre-body capture
  deterministic (`false`), so the finally branch no longer depends on the shell.
  Verified passing with and without the exported var.
- Residual corner (not a finding): with putenv/getenv in disable_functions in the TEST
  environment, cleanup degrades and the fallback test would fatal — tests are not run
  under hardened ini; production is guarded (N1).

### NEW findings (round 3)

| # | file:line | What is wrong | Severity | Handled later |
|---|---|---|---|---|
| R3-N1 | tests/ConfigCacheGuardConfigTest.php:13, tests/ConfigLoaderTest.php:17 | `private string\|false $savedTrustEnv;` is an uninitialized typed property read in tearDown. PHPUnit runs tearDown even when setUp throws (fixture teardown sits outside the main try, in its own catch-all — vendor/phpunit/phpunit/src/Framework/TestCase.php:778-807), so a throw in ConfigLoaderTest::setUp's `mkdir` (I/O error, full disk) would mask the original error with "Typed property ... must not be accessed before initialization". ConfigCacheGuardConfigTest is safe (capture precedes any throwable op). Fix: `$savedTrustEnv = false;` default (one token). | nit | **FIXED** — both properties now default to `false` (one-token initializer). |
| R3-N2 | tests (suite accounting) | Full-suite count vs round-2 record: 2301 vs 2299 tests with no test-method change in the round-3 delta (git diff confirms); both today's runs (var exported / not) are internally identical (2301/16884/32, 0 failures). Cause of the round-2 mismatch not identified; informational only. | info | No action — informational (count drift not reproduced either way). |

Informational (not findings): round-2 dispositions F1–F9 remain verified as recorded —
no round-3 change touches the allowlist, the precedence pins, the integration tests, or
the docs; C1 (pre-existing, out of scope) and the FAQ-005 KB amendment stay open as
recorded. Strict-path byte-identity re-verified this round by tokenizer (0 master
strings missing; HEAD adds exactly the 2 downgrade-warning halves).
