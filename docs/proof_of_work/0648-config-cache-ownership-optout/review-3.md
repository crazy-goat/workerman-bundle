# review-3 — issue #648: explicit opt-out for the config-cache permission guard

Review round: 3 (mandatory critical review — security-relevant policy diff, third pass).
Branch: `feat/issue-648-add-an-explicit-opt-out-for-the-config-c`
Round-3 delta reviewed: `git diff 7124f3c..HEAD` (commit ee727f9 "fix: guard getenv() against
disable_functions, hermetic env in tests, correct F7 comments").
Full change re-read: `git diff origin/master..HEAD` (113b2b8 → ee727f9, 1304 insertions / 20 deletions).

## Executive verdict

**READY for PR.** All three round-2 findings are disposed as **FIXED**, each verified
empirically (not just by reading the diff):

- **N1**: `function_exists('getenv')` guard in place; `php -d disable_functions=getenv`
  probe returns `resolve() === false` with exit code 0 — no fatal; the superglobal path
  still downgrades when getenv is disabled.
- **N2**: both test classes capture/delete/restore the process env *and* unset the
  superglobals in setUp/tearDown. The exact command from the disposition passes with
  and without the exported variable (54 tests / 122 assertions / 3 skips, identical
  both ways), and the **full suite is green with the variable exported**
  (2301 tests / 16884 assertions / 32 skips / 0 failures), which is the strongest
  hermeticity statement available.
- **N3**: both chown-test comments now state the true rationale; the F7 disposition
  wording ("both" chown tests) is corrected.

Two new round-3 items, both trivial: R3-N1 (nit — uninitialized typed test property read
in tearDown; one-token fix `= false`, recommended before the PR since the branch is
already touching those exact lines) and R3-N2 (info — full-suite test-count drift
2301 vs round-2's 2299 with no test-method change in this delta; both today's runs are
internally identical). Both non-blocking.

Strict path remains byte-identical to origin/master (re-verified mechanically this round:
every quoted string in master's ConfigLoader.php is present in HEAD; the only HEAD-only
strings are the two halves of the downgrade warning). Docs (security.md / README /
CHANGELOG / ConfigurationTreeBuilder) consistent claim-for-claim. All gates green.

## Procedure compliance

1. **docs/helpers tag index** read first (not the whole files): faq.md index
   (`config-cache` → FAQ-005, `config` → FAQ-024/035, `permissions` → FAQ-005,
   `security` → FAQ-027, `coverage` → FAQ-010/011, `docs` → FAQ-019); decisions.md index
   (`security` → DEC-005/006/010/013/015, `policy` → DEC-006–009, `coverage` → DEC-007,
   `docs` → DEC-012). Read entries: FAQ-005 (still absolute: "hard boot RuntimeException",
   KB-owned — amendment re-proposed, round 3), FAQ-024/027/035/010/011/019, DEC-005/006/
   007/008/009/010/012/013/015. No writes to docs/helpers/.
2. **findings-review.md** read in full (rounds 1 and 2) before verifying anything.
3. **Round-3 delta** read in full (`git diff 7124f3c..HEAD`); full change re-read.
4. Gates re-run locally (table below), including the empirical N1/N2 probes.

## Gates (re-run this round)

| Gate | Result |
|---|---|
| PHPStan level 8 — full repo | OK, no errors |
| php-cs-fixer `--dry-run` (repo config) | OK — 0 of 252 files |
| rector `--dry-run` (full repo) | OK |
| `bin/kb-lint.php` | OK — 1 pre-existing warning (faq.md line budget 350/300, unrelated) |
| `bin/check-changelog.php` | OK — structurally valid |
| `WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE=1 vendor/bin/phpunit tests/ConfigLoaderTest.php tests/ConfigCacheGuardConfigTest.php` | OK — 54 tests, 122 assertions, 3 skipped, 0 failures |
| same, **without** the exported var | OK — identical counts (54/122/3, 0 failures) |
| `--filter 'ConfigCacheGuardConfigTest\|ConfigLoaderTest'` | OK — 62 tests, 130 assertions, 3 skipped (identical to round-2's recorded numbers) |
| Full suite with exported var (`-d phar.readonly=0`; see note) | OK — 2301 tests, 16884 assertions, 32 skipped, 0 failures |
| Full suite without the var (same flags) | OK — identical counts (2301/16884/32, 0 failures) |
| `php -d disable_functions=getenv` probe (N1) | resolve()=false, exit 0, no fatal; `$_SERVER` downgrade still works |
| Byte-identity (tokenizer, all quoted strings in ConfigLoader.php) | master strings all present; HEAD-only = exactly the 2 downgrade-warning halves |

Note on the full-suite runs: this machine's Homebrew PHP has `phar.readonly=On`, which
makes the repo's own guard test `PharReadOnlyGuardTest::testPharReadOnlyIsDisabled` fail
by design (it fails with and without the exported var — verified), and inflates skips.
Running with `-d phar.readonly=0` reproduces round-2's baseline conditions (32 skips).
The first background full-suite run also showed 5 transient failures / 1 error on the
daemon/process tests that did not reproduce on the second run — the known macOS
flake pattern (FAQ-007/009), unrelated to the branch.

## Per-finding verdicts (N1–N3)

### N1 — `function_exists('getenv')` guard — **FIXED**

Evidence:
- Guard placement (src/ConfigCacheGuardConfig.php:76-81): inside the last-resort
  fallback block, `$env = function_exists('getenv') ? getenv(self::ENV_VAR) : false;`
  — exactly the disposition request. When getenv is unavailable, `$env === false` →
  `$raw = ''` → `return false` (strict). When the superglobals already carry a value,
  getenv is never consulted, so the guard sits only on the path that needs it.
- Comment (lines 76-78) is accurate ("resolve() runs at boot on every path, so it must
  not fatal even in strict mode when the fallback is unavailable").
- Empirical probe:

  ```
  $ php -d disable_functions=getenv -r 'require "vendor/autoload.php"; use ...\ConfigCacheGuardConfig;
      var_dump(function_exists("getenv")); var_dump(ConfigCacheGuardConfig::resolve());'
  bool(false)
  bool(false)          # no fatal, strict mode intact, exit 0
  ```
  and with `$_SERVER[ENV_VAR] = '1'`: `bool(true)` — the opt-out still functions via
  the superglobals when getenv is disabled.
- Behavior is bit-identical to round 2 on any normal runtime (`function_exists('getenv')`
  is `true`), so no regression surface was introduced by the guard itself.
- Informational: the branch already contained an unguarded pre-existing
  `\getenv('GRPC_ENABLE_FORK_SUPPORT')` at src/Runner.php:207 (unchanged from
  origin/master); the hardening posture is now inconsistent (guarded here, bare there),
  but Runner.php is pre-existing and out of scope — recorded, not a finding.

### N2 — test-suite hermeticity — **FIXED**

Evidence:
- Both classes implement the full capture/delete/restore discipline:
  - tests/ConfigCacheGuardConfigTest.php:12-26 (setUp) / 28-39 (tearDown),
  - tests/ConfigLoaderTest.php:16-34 (setUp) / 36-48 (tearDown).
  Capture is `function_exists('getenv') ? getenv(ENV_VAR) : false`; deletion is
  `putenv(ENV_VAR)` without `=` (absent ≠ empty: an exported empty value round-trips
  via `putenv('X=')`); restore is delete-when-false / `putenv('X='.$old)` otherwise.
  Superglobals are unset in **both** setUp and tearDown in **both** classes.
- Bonus beyond the disposition: round 2's N2 narrative noted ConfigLoaderTest "*never
  unsets the superglobals*" as a pre-existing leak — the round-3 delta now unsets them
  in that class too, so the disposition's "superglobals unset as before" understates
  the fix (it closed the pre-existing `$_ENV`/`$_SERVER` leak as well).
- Empirical (the exact disposition command):
  - `WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE=1 vendor/bin/phpunit tests/ConfigLoaderTest.php tests/ConfigCacheGuardConfigTest.php` → **54/122/3, 0 failures**
  - without the var → **identical counts**.
- Mechanism probe (proves the delete is what protects the suite, not luck):
  with the var exported, simulating the old setUp (reset + superglobal unset only)
  yields `resolve() = true` (leak live); adding `putenv(ENV_VAR)` yields `false` (fixed).
- Full-suite hermeticity: full suite with the var exported is green (2301/16884/32) —
  no test outside the two classes consumes the guard (grep-verified: only
  ConfigLoaderTest + ConfigCacheGuardConfigTest reference ENV_VAR/resolve/set), and the
  exported var demonstrably cannot affect anything else.

**Hermeticity edge cases (asked explicitly):**

1. *Can the putenv restore in one test class contaminate the other?* **No.**
   Both classes run in the same PHP process; the invariant "after tearDown the process
   env equals the value at suite start" holds inductively: every tearDown restores
   exactly what its own setUp captured, and every setUp captures what the previous
   tearDown left (the original shell value) — order-independent, so interleaving cannot
   break it. The only test that puts a value mid-test
   (`testResolveFallsBackToGetenvWhenSuperglobalsAreEmpty`) restores in `finally`
   before tearDown runs. `$_ENV`/`$_SERVER` are startup snapshots independent of
   putenv, which is why both dimensions are cleaned in both classes.
2. *Does `testResolveFallsBackToGetenvWhenSuperglobalsAreEmpty` still prove the getenv
   fallback, given setUp deletes the env var?* **Yes — and it proves it non-vacuously.**
   The proof obligation is "resolve() consults getenv() when both superglobals are
   empty". The test body itself runs `putenv(ENV_VAR.'=1')` *after* setUp's deletion;
   with both superglobals empty, `resolve() === true` is only reachable through the
   getenv fallback. If resolve() ignored getenv the assertion would fail. The setUp
   deletion actually makes the test *more* deterministic: the pre-body capture
   `$env = getenv(ENV_VAR)` is now always `false`, so the finally-branch is no longer
   data-dependent on the developer's shell. Verified empirically: passes with the var
   exported and with it absent (two runs each).
3. Residual corner (acceptable): if `putenv`/`getenv` were in `disable_functions` in
   the *test* environment, cleanup would silently degrade and the getenv-fallback test
   would fatal on its bare `getenv()` call. Tests are not run under hardened ini
   (PHPUnit itself would break long before), and production is guarded — recorded, not
   a finding.

### N3 — chown-test comment rationale — **FIXED**

Evidence:
- tests/ConfigLoaderTest.php:442-445 (strict-refusal chown test) now reads:
  "warmUp() pins umask(0077) internally, but pinning the mode here keeps the test
  independent of how the directory is created and of future changes, so exactly one
  refusal signal fires." — no false mechanism claim; accurate (the pin is
  defense-in-depth, per round-2's analysis that the umask-000 mechanism is a phantom;
  warmUp()'s `umask(0077)` pin confirmed at src/ConfigLoader.php:55-60).
- tests/ConfigLoaderTest.php:870-873 (foreign-owned trust test) says the same with
  "assertCount(1) stays deterministic" — matches the disposition wording exactly.
- F7 disposition in findings-review.md corrected: "both chown-based tests (strict
  refusal :424 and foreign-owned trust :851)" — exactly two chown tests, both pinned
  (at HEAD the pins are at :446/:874; the ~18-line drift from :424/:851 is the setUp
  growth in this same commit, expected).
- Disposition's "pin keeps the test independent of how the dir mode is established /
  assertCount determinism" matches the comments verbatim in substance.

## Round-3 delta scan for new issues

The production delta is five lines (the guard) and the test delta is twelve (setUp/
tearDown + comments). Scanned:

- Guard: correct operator (`?:` would not help — `function_exists` is the discriminator),
  no TOCTOU concern (single boot-time read), no double evaluation issue, comment accurate.
- Test property declaration: see R3-N1 below.
- Test setUp ordering: superglobals unset before the getenv capture — no interference
  (getenv reads the process env, unaffected by unsetting superglobals).
- Restore edge cases: exported empty value (`''` ≠ false) round-trips correctly through
  `putenv('X=')`; values containing `=` restore correctly (putenv takes `NAME=VALUE`
  with VALUE free of NUL); nothing can restore a value the shell never had.
- `testResolveReadsTruthyEnvValueFromEnv` still leaves `$_ENV` set after the test body —
  harmless: the next test's setUp unsets it, and round-2's precedence pins make the
  shadowing irrelevant. Not a finding.
- F5's `finally` (unset superglobal + reset holder) remains correct alongside the new
  setUp deletion — no putenv was done in that test, so nothing to restore there.

## Strict-path byte-identity (re-verified this round)

Token-based comparison (T_CONSTANT_ENCAPSED_STRING) of origin/master:src/ConfigLoader.php
vs HEAD: 41 master strings, **0 missing**; HEAD adds exactly 2 strings — the two halves
of the downgrade warning ("The config-cache permission guard is explicitly downgraded
(%s is set) and the cache " / "directory is trusted as-is by the deployment; loading
proceeds despite: %s"). Exception type (`\RuntimeException`), throw site, branch order
(error before warn), and the PSR-3/`E_USER_WARNING` plumbing are diff-identical to
master (the `git diff` shows no `-`/`+` on those lines — the refactor to `verdict()`
preserved them byte-for-byte). The strict `error` strings are pinned by the
`assertStringNotContainsString(ENV_VAR, ...)` purity tripwire (tests/ConfigLoaderTest.php:668).

## Claim-by-claim docs consistency (final gate)

- **security.md "Guard downgrade" (:573-618)**: sources (`$_SERVER`/`$_ENV`/`getenv()`)
  match the implementation; allowlist and fail-closed polarity match
  (`1/true/on/yes`, case/whitespace-insensitive; typo example `ture` matches the test
  matrix); "four refusal checks" matches the four branches; PSR-3-or-`E_USER_WARNING`
  degradation matches validateCacheFilePermissions; "strict mode is the default —
  no behaviour change for existing users" matches the byte-identity proof; DEC-006
  naming correct. No round-3 change touches this file.
- **README.md (:228-247)**: absolute claim remains, immediately disclaimed by the
  opt-out note with security-downgrade framing and the working
  `#guard-downgrade-explicit-opt-out` anchor (matches the security.md heading slug).
  Unchanged by round 3.
- **CHANGELOG.md (:12-23)**: "read from the environment of the booting process",
  four checks, warning path, strict default — all accurate; unchanged by round 3.
- **ConfigurationTreeBuilder**: no mention of the opt-out — correct for an env-only
  design; no contradiction. Grep-verified.
- **KB (docs/helpers/)**: FAQ-005 still absolute ("hard boot RuntimeException, not a
  warning") — KB-owned, amendment re-proposed (now round 3, still pending).

## What an automated check could not catch (for the record)

- N1's exact failure mode (boot fatal under `disable_functions=getenv`) — only the
  manual `-d` probe exercises it; now guarded and probe-verified.
- N2's exported-var suite breakage — only a hermeticity run reveals it; now verified
  green in both directions, including the full suite with the var exported.
- R3-N1 (tearDown-after-setUp-throw masking) — PHPUnit's runBare structure is not a
  gate; read from vendor/phpunit/phpunit/src/Framework/TestCase.php:778-807 (fixture
  teardown runs outside the main try, in its own catch-all).
- The phar.readonly local-environment failure — host-specific, unrelated to the branch
  (fails identically with and without the exported var; the guard test is designed to
  fail on misconfigured runtimes).

## Candidate KB entries (proposed only — main session decides; nothing written)

1. **FAQ-005 amendment** (re-proposed, round 3; still pending): add one sentence —
   "Since #648 an explicit opt-out exists (`WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE=1`)
   that downgrades the refusal to a boot-time warning; see security.md 'Guard downgrade'."
   tags=config-cache,permissions,docker.
2. **FAQ (suite hermeticity; from N2)**: "An env-var-gated feature makes its tests
   environment-sensitive — clear the process env (`putenv`), not just the superglobals;
   clean up in setUp/tearDown even when the code under test reads `getenv()`."
   tags=tests,config-cache,env. One paragraph including the capture/restore discipline
   (`getenv()` capture, `putenv(X)` delete, `putenv('X='.$old)` restore) and the
   cross-class safety invariant.
3. **FAQ (hardened ini; from N1)**: "Boot-critical code must not call functions that
   hardening may disable — guard `getenv()` with `function_exists()`."
   tags=security,config-cache,env,php. One paragraph: `disable_functions=getenv` makes a
   bare call fatal at boot with a misleading message; `function_exists()` returns false
   for disabled functions; keep the superglobals path working when the fallback is gone.
4. **FAQ (failure-mechanism reproduction; from N3)**: "A fix must reproduce its own
   failure mechanism first": the umask-000 world-writable-dir alarm was impossible
   because `warmUp()` pins `umask(0077)` (Filesystem::mkdir 0777 & ~0077 = 0700); verify
   the claimed mechanism before adding defensive pins so comments stay truthful.
   tags=tests,permissions,config-cache.
5. **FAQ (test fixtures; from R3-N1)**: "Initialize typed test-fixture properties —
   PHPUnit runs tearDown even when setUp throws (vendor TestCase.php runBare), so an
   uninitialized typed property read in tearDown masks the original error."
   tags=tests,phpstan.
6. **DEC-016 (round-1 candidate, now implemented — record it)**: "Security-guard opt-out
   env parsing must fail closed" — #648 round 2 replaced the blacklist with the
   allowlist `1/true/on/yes` and pinned it. tags=security,policy,config-cache,env.

## Overall verdict

**READY for PR.** N1–N3 all verified FIXED with empirical evidence; the round-2
disposition commands reproduce exactly; the full suite is green even with the opt-out
variable exported from the shell; strict messages remain byte-identical to
origin/master; docs are claim-for-claim consistent; PHPStan level 8, cs-fixer, rector,
kb-lint and check-changelog all pass. R3-N1 (nit: initialize `$savedTrustEnv = false`)
is a one-token hardening of code this commit already touches — recommend folding it in
before the PR, non-blocking either way. R3-N2 is informational.
