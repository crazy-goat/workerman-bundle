# review-2 — issue #648: explicit opt-out for the config-cache permission guard

Review round: 2 (mandatory critical review — security-relevant policy diff, second pass).
Branch: `feat/issue-648-add-an-explicit-opt-out-for-the-config-c`
Round-2 delta reviewed: `git diff 113b2b8..HEAD` (commit 7124f3c "fix: fail-closed opt-out
parsing, getenv fallback, README/security docs, pin downgrade tests").
Full change re-read: `git diff origin/master..HEAD` (113b2b8, 1032 insertions / 20 deletions).

## Executive verdict

**READY for PR.** All ten round-1 dispositions verify as fixed (F8 n/a). The medium F1
fail-open parsing is replaced by a fail-closed allowlist, pinned by tests, and
documented claim-for-claim in security.md. Strict path still byte-identical to
origin/master (re-verified mechanically), the downgrade still always emits a warning,
all gates green, full suite green (2299 tests / 0 failures / 32 skips).

Three new findings in the round-2 delta — all low/nit, none blocking:

- **N1 (low)**: `getenv()` at src/ConfigCacheGuardConfig.php:76 is a new boot-critical
  function call; if `getenv` is in `disable_functions` (hardened setups), `resolve()`
  fatals with `Error: Call to undefined function getenv()` at boot *even in strict
  mode* — demonstrated empirically. Fail direction is loud (boot crashes), but the
  error is unrelated to permissions and confusing. One-line mitigation:
  `function_exists('getenv')` guard.
- **N2 (low)**: test-suite hermeticity. The getenv fallback means a shell that exports
  `WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE=1` breaks the suite — demonstrated empirically:
  `testResolveIsFalseByDefault`, `testResolveTreatsAbsentEmptyAndFalsyEnvValuesAsStrict`,
  and strict `ConfigLoaderTest` tests (no RuntimeException + leaked E_USER_WARNING) all
  fail. `setUp`/`tearDown` clear superglobals and the holder but never the process env
  (`putenv`). Realistic scenario: a developer testing the opt-out manually in the same
  shell that runs `composer test`.
- **N3 (nit)**: the F7 fix comments (tests/ConfigLoaderTest.php:421-423, :848-850)
  describe a mechanism that cannot occur: `ConfigLoader::warmUp()` pins `umask(0077)`
  around `ConfigCache::write()`, so Symfony `Filesystem::mkdir(0777)` creates
  `cache/workerman` with 0700 under **any** outer umask — verified empirically
  (perms 0700 with outer umask 0000). Round-1 F7 was therefore likely a phantom
  finding; the added pins are harmless but redundant, and the disposition's "all three
  chown-based tests" overcounts (there are exactly two chown tests, both pinned).

## Procedure compliance

1. **docs/helpers tag index** (re-read for round 2): faq.md index (`config-cache` →
   FAQ-005, `config` → FAQ-024/035, `permissions` → FAQ-005, `security` → FAQ-027,
   `coverage` → FAQ-010/011, `docs` → FAQ-019); decisions.md index (`security` →
   DEC-005/006/010/013/015, `policy` → DEC-006–009, `coverage` → DEC-007, `docs` →
   DEC-012). Re-read **FAQ-005** (still absolute: "hard boot `RuntimeException` …
   not a warning" — KB-owned, unchanged, needs the round-1 proposed amendment) and
   **DEC-006** (loosen bar — the opt-out now names DEC-006 in security.md and is
   explicit/documented/visible; compliant) and **DEC-009** (main session is the only KB
   writer — no writes performed; candidates proposed at the end).
2. **findings-review.md** read first; every disposition verified against the actual
   code/tests (see per-finding verdicts below).
3. **Round-2 delta** read in full; full change re-read (`git diff origin/master..HEAD`).
4. Gates re-run locally (below).

## Gates (re-run this round)

| Gate | Result |
|---|---|
| PHPStan level 8 — full repo (`vendor/bin/phpstan analyze`) | OK, no errors |
| PHPUnit — full suite (PHP 8.5.9, `--no-coverage`) | OK — 2299 tests (9 more than round 1), 16878 assertions, 32 skipped, 0 failures |
| PHPUnit — touched classes (filter `ConfigCacheGuardConfigTest\|ConfigLoaderTest`) | OK — 62 tests, 130 assertions, 3 skipped (root-only chgrp/chown, pre-existing pattern) |
| php-cs-fixer `--dry-run` | OK, 0 of 252 files |
| rector `--dry-run` (touched files) | OK |
| `bin/kb-lint.php` | OK — 1 pre-existing warning (faq.md line budget 350/300, unrelated) |
| `bin/check-changelog.php` | OK — structurally valid |

## Per-finding verdicts (F1–F9; full evidence in findings-review.md Round 2 section)

| # | Verdict | Evidence (this round) |
|---|---|---|
| F1 | **FIXED** | `resolve()` (src/ConfigCacheGuardConfig.php:86-88) is a strict allowlist: `in_array($normalized, ['1','true','on','yes'], true)`, case/whitespace-insensitive via `strtolower(trim(...))`. `0/false/off/no`, typos (`ture`, `banana`, `enabled`, `1.0`, `yes please`, `ONN`) → false — pinned in `testResolveTreatsAbsentEmptyAndFalsyEnvValuesAsStrict` + `testResolveTreatsUnrecognisedEnvValuesAsStrict`. Empty-string-per-source handling (lines 71-78) reintroduces no shadowing gap: each source is skipped only when exactly `''`; a non-empty `'0'` in `$_SERVER` shadowing truthy `$_ENV` is intended first-non-empty precedence, matching the docblock. security.md:585-591 states allowlist + fail-closed polarity accurately. Round-2 semantics are a strict subset of round-1's: no value that round 1 treated as false now resolves true; previously-true typos now fail closed. |
| F2 | **FIXED** | `getenv()` third fallback at lines 75-78 (`$env === false ? '' : $env`); pinned in `testResolveFallsBackToGetenvWhenSuperglobalsAreEmpty` (putenv → assert → finally-restore, correct for both `$env === false` (putenv without `=`) and string values including `''`/`'0'`; no cross-test leak). security.md:585 now says `$_SERVER`/`$_ENV`/`getenv()`. **But see N1/N2** — the fallback carries two side-effects. |
| F3 | **FIXED** | README.md:240-247 gains the opt-out note under "Config cache and runtime user" with the env var name, security-downgrade warning, and pointer to `#guard-downgrade-explicit-opt-out` (anchor matches security.md:573 heading exactly). FAQ-005 remains KB-owned with its absolute claim — amendment re-proposed. |
| F4 | **FIXED** | `testLoadFromCacheTriggersEUserWarningWhenTrustSetAndNoLogger` (tests/ConfigLoaderTest.php:873-915): world-writable dir (chmod dirname 0777) + `set(true)` + no logger → `set_error_handler(E_USER_WARNING)` capture; asserts message contains both `world-writable` and the ENV_VAR marker; handler restored and holder reset in `finally`. Mirrors the :492 precedent. Passes (green run above). |
| F5 | **FIXED** | `testLoadFromCacheProceedsWithWarningForWorldWritableDirectoryWhenTrustSet` (:774-821) drives the downgrade via `$_SERVER[ENV_VAR]='1'` — no `set()` call anywhere in that test path. The chain is the real production one: `getWorkermanConfig()` → `getConfig()` → `loadFromCache()` → `validateCacheFilePermissions()` → `ConfigCacheGuardConfig::resolve()` (verified in src/ConfigLoader.php:87-98, :160-169 and Runner.php:35-39). `finally` unsets `$_SERVER[ENV_VAR]` and resets the holder. Exactly one warning asserted; the dir is chmod'd to 0777 deterministically, so the count is umask-independent. |
| F6 | **FIXED** | Empty `$_SERVER` value no longer shadows truthy `$_ENV` (per-source `''` skip, lines 71-78); pinned in `testResolveFallsBackToEnvWhenServerValueIsEmpty`. |
| F7 | **fixed — with caveat (see N3)** | Both chown-based tests pin the containing dir: `testLoadFromCacheRefusesCacheFileOwnedByAnotherUser` (:424) and `testLoadFromCacheProceedsWithWarningForForeignOwnedFileWhenTrustSet` (:851). That satisfies the disposition literally. But there are exactly two chown tests, not "all three" as the disposition says, and the pins' rationale (world-writable warm-up dirs under umask 000) is mechanically impossible (§N3) — round-1 F7 was very likely a phantom alarm. |
| F8 | n/a | Not a finding (as round 1 stated). Warning wording `(%s is set)` unchanged and accurate. |
| F9 | **FIXED** | (a) `testCheckCacheFilePermissionsWithTrustAcceptsSecurePermissions` (:756-772) asserts `['warn'=>null,'error'=>null]` with trust=true + safe perms; (b) strict purity pin `assertStringNotContainsString(ENV_VAR, error)` in `testCheckCacheFilePermissionsRefusesFileOwnedByAnotherUser` (:646) — one pin suffices as tripwire since all four strict branches share `verdict(false)` returning `$message` untouched; (c) superseded by F1's allowlist pins. |

## Section A / B informational rows (C1–C4, B1–B3)

All re-checked against the round-2 delta; **no round-2 change affects them** — verdicts stand
as recorded in findings-review.md Section A/B: C1 REAL (pre-existing, out of scope, unchanged),
C2 REAL-but-incomplete → README part now fixed by F3, C3 REAL (BC-safe 8th param, no callers
changed), C4 REAL (suite green), B1/B2/B3 informational/accepted as recorded.

## New findings (round-2 delta and round-1 misses)

| # | file:line | What is wrong | Severity |
|---|---|---|---|
| N1 | src/ConfigCacheGuardConfig.php:76 | `getenv()` is a new function call in a boot-critical guard. With `getenv` in `disable_functions` (hardened setups), `resolve()` raises `Error: Call to undefined function getenv()` — demonstrated with `php -d disable_functions=getenv`. The crash happens on the strict path too (superglobals empty is the common CLI case), with an error message unrelated to permissions. Direction is fail-loud (safe), but it is a new crash vector and a confusing boot failure. Fix (one line): `($env = function_exists('getenv') ? getenv(self::ENV_VAR) : false)` or an explicit `?? ''`-style guard. | low |
| N2 | tests/ConfigCacheGuardConfigTest.php:12-22, tests/ConfigLoaderTest.php:23-27 (+ src/ConfigCacheGuardConfig.php:76) | Test-suite hermeticity: `setUp`/`tearDown` clear the static holder and the superglobals but never the process environment. Since the getenv fallback now reads the process env (not the superglobals), a shell exporting `WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE=1` breaks the suite — demonstrated empirically: `testResolveIsFalseByDefault` and `testResolveTreatsAbsentEmptyAndFalsyEnvValuesAsStrict` fail, and strict `ConfigLoaderTest` tests fail (no RuntimeException expected + an E_USER_WARNING leaks to PHPUnit's failOnWarning). Realistic: a developer testing the opt-out manually in the same shell that runs `composer test`. The `$_ENV`-based variant of this leak pre-existed round 2 (ConfigLoaderTest never unsets the superglobals); the getenv fallback widens it to every `variables_order` and to the guard's own unit class. Fix: `putenv(ENV_VAR)` delete (and restore) in both setUp/tearDown pairs, mirroring the restore discipline of `testResolveFallsBackToGetenvWhenSuperglobalsAreEmpty`. | low |
| N3 | tests/ConfigLoaderTest.php:421-423, :848-850 | The F7 fix comments assert "under a permissive umask (e.g. 0000) the directory created during warm-up would itself be world-writable". Mechanically false: `ConfigLoader::warmUp()` (src/ConfigLoader.php:55-60) pins `umask(0077)` around `cache->write()`, and Symfony `Filesystem::mkdir($dir, 0777)` (vendor/symfony/filesystem/Filesystem.php:90-98) applies the mode masked by the *current* umask → `cache/workerman` is born 0700 under any outer umask — verified empirically (0700 with outer umask 0000; also independently verified by a live run of the full suite under a permissive umask-agnostic rerun). The pins are harmless (idempotent) but redundant; round-1 F7's described failure mode cannot occur, so round 1 over-reported and round 2 over-fixed with a wrong rationale. Also: the disposition's "all three chown-based tests" overcounts — there are two. Fix: correct or drop the comments (or drop the redundant pins). | nit |

## Re-verification of round-1 security assertions (unchanged by round 2, re-checked)

1. **Strict path byte-identity**: re-verified mechanically — tokenizer comparison of all
   quoted strings in `origin/master:src/ConfigLoader.php` vs HEAD: every master string is
   present in HEAD; the only HEAD-only strings are the two halves of the downgrade warning
   ("The config-cache permission guard is explicitly downgraded (%s is set) ...").
   Exception type (`\RuntimeException`), throw site, branch ordering (error first), and the
   PSR-3/`E_USER_WARNING` warn plumbing are untouched; round-2 delta does not modify
   src/ConfigLoader.php at all.
2. **Fail-closed matrix** (every input → outcome, all verified): absent → strict; `''` any
   source → next source, then strict; `0/false/off/no` (any case, trimmed) → strict; typo
   (`ture`, `banana`, `enabled`, `1.0`, `yes please`, `ONN`) → strict; `1/true/on/yes` (any
   case, trimmed) → downgrade. Holder override (`set()`) has no production callers
   (grep-verified: only tests). No input can silently unlock the guard.
3. **Every downgraded branch still warns**: `verdict(true)` always returns a `warn` (never
   silent), embedding the original refusal text + the env-var marker; F4 now pins the
   no-logger variant. DEC-006 visibility invariant holds.
4. **Docs consistency**: security.md Guard downgrade subsection (573-618) matches
   implementation claim-for-claim (sources, allowlist, fail-closed, four branches, strict
   default, DEC-006 naming); README note matches; CHANGELOG entry accurate ("read from the
   environment of the booting process"); ConfigurationTreeBuilder has no mention of the
   opt-out (env-only design — no contradiction found); anchors `#guard-downgrade-explicit-opt-out`
   correct in README + security.md:466/477/519. FAQ-005 (KB-owned) still carries the
   absolute claim — amendment re-proposed.
5. **Coverage**: every new round-2 line is executed by the new tests (13-guard-config
   matrix incl. both fallthrough orders, trim/case-fold, holder override; ConfigLoader:
   verdict warn/strict, resolve propagation, no-logger branch, trust-accept branch). CI
   must confirm the 80% floor with a coverage driver (none installed locally).

## Candidate KB entries (proposed only — main session decides; nothing written)

1. **FAQ-005 amendment** (re-proposed from round 1; still pending and now more urgent
   since README+security.md are fixed): add one sentence — "Since #648 an explicit opt-out
   exists (`WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE=1`) that downgrades the refusal to a
   boot-time warning; see security.md 'Guard downgrade'." tags=config-cache,permissions,docker.
2. **FAQ (new, N2)**: "An env-var-gated feature makes its tests environment-sensitive —
   clear the process env (`putenv`), not just the superglobals." tags=tests,config-cache,env.
   One paragraph: `setUp`/`tearDown` that unset `$_SERVER`/`$_ENV` and a static holder do
   not protect against a shell-exported variable once the code reads `getenv()`; the
   suite breaks with confusing failures exactly when a developer is manually testing the
   feature. Restore discipline: capture `getenv()` before, `putenv()` without `=` when
   false, `putenv('X='.$old)` otherwise (pattern already in
   `testResolveFallsBackToGetenvWhenSuperglobalsAreEmpty`).
3. **FAQ (new, N3)**: "A fix must reproduce its own failure mechanism first: the
   umask-000 world-writable-dir alarm was impossible because `warmUp()` pins
   `umask(0077)`." tags=tests,permissions,config-cache. One paragraph: chmod/chown tests
   assert on directories the bundle itself creates under a pinned 0077 umask
   (Filesystem::mkdir 0777 & ~0077 = 0700), so "permissive outer umask" never reaches
   those dirs; verify the claimed mechanism (e.g. `umask(0000)` reproduction) before
   adding defensive pins, so comments and code stay truthful.
4. **DEC-016 (round-1 candidate — now implemented; record it)**: "Security-guard opt-out
   env parsing must fail closed" — record that #648 round 2 replaced the blacklist
   (`anything except 0/false/no/off`) with the allowlist `1/true/on/yes` and pinned it.
   tags=security,policy,config-cache,env.
5. **FAQ (new, N1)**: "Boot-critical code must not call functions that hardening may
   disable — guard `getenv()` with `function_exists()`." tags=security,config-cache,env,php.
   One paragraph: `disable_functions` may remove `getenv`; a bare call then fatals at
   boot on the strict path with a message unrelated to the guard. `function_exists()`
   returns false for disabled functions, making the guard the correct one-liner.

## What an automated check could not catch (for the record)

- N1 (getenv-disabled crash vector) — no gate exercises `disable_functions=getenv`.
- N2 (exported-var suite breakage) — CI does not export the var; only a hermeticity run
  (`WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE=1 vendor/bin/phpunit`) reveals it, which I ran.
- N3 (false rationale in fix comments) — the mechanism claim lives in prose; the pins
  pass all gates either way.

## Overall verdict

**READY for PR.** F1–F9 dispositions verified (F7 with the N3 caveat); the security-critical
fail-open hole is closed and pinned; strict path byte-identical; docs in lock-step; all
gates and the full suite green. N1–N3 are non-blocking; N1 and N2 are cheap to address
(one `function_exists()` guard; putenv cleanup in two setUp/tearDown pairs) and I
recommend folding at least N1 into the branch before the PR, since it is the only new
production-code risk (fail-loud but misleading).
