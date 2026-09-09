# Review Round 4 (CI-fix round) — Issue #588: composer.json dependency hygiene

Branch: `chore/issue-588-composer-json-ships-an-unused-dependency` (uncommitted delta on top of `0912280`)
Scope (vs `HEAD`): `tests/ResponseTest.php`, `tests/Strategy/BinaryFileResponseReflectorTest.php`,
`tests/Strategy/BinaryFileResponseStrategyTest.php`, `tests/StreamedBinaryFileResponseTest.php`
(+ proof files `findings-review.md` CI-round entry — read; untracked `code-decision-2.md` — read).
Zero `src/`, `composer.json`, `tests.yaml`, `CHANGELOG.md` delta: test-only fix for PR #806
run 34403956293 (4 errors + 2 failures on all four Symfony 6.4 legs; 7.4/8.0 legs green).

Verdict: **clean** — 0 high, 0 medium, 0 low, 0 nit. All prior items are fixed or
deliberately deferred as decided; the R-CI-1 fix is sound and verified to the extent
locally possible (installed tree is Symfony 8.1; the 6.4 half is verified against the
6.4.0 source). No new findings. Safe to re-push; the 6.4 matrix legs are the oracle.

## 1. Helpers compliance (tag index first, then matching entries only)

Tags matching this diff: `http`, `binary-file`, `response-strategy`, `tests`, `ci`, `coverage`,
`docs`, `markdown`, `lint`, `policy`. Entries read: FAQ-002, FAQ-032, FAQ-011, DEC-001, DEC-002,
DEC-007, DEC-008, DEC-009, DEC-012. **No violations.**

- FAQ-002 (BinaryFileResponse HEAD/temp-file strategy): the diff only *skips* temp-file
  tests on 6.4; no strategy, converter, or `withFile()` path touched. BC-safe by construction.
- FAQ-032 (workflow-pin sweep): N/A — no workflow file in the delta, so no `grep -rl` sweep
  needed; targeted test run (below) is correctly scoped to the four touched test files.
- FAQ-011 / DEC-007 (coverage floor): `coverage:check` untouched; skipped-on-6.4 tests do not
  erode the 80% floor ( CI gate counts covered lines, and skips are version-conditional).
- DEC-001 / DEC-002 (response-strategy architecture): no `src/Http` change. N/A.
- DEC-008 (lint is canonical): no new check added. N/A.
- DEC-009 (single writer): one candidate proposed below (C-3); nothing appended here.
- DEC-012 (no raw `<...>` in Markdown): new proof prose + code comments scanned — none.
- Tag-level matches skipped on trigger mismatch (index + trigger lines only): FAQ-025
  (header-gate internals — no header parsing touched), FAQ-037 (new PHP syntax — the delta
  uses `property_exists`, available since forever), FAQ-038 (`/proc` seams), FAQ-030
  (fork helpers), FAQ-006 (inotify).

## 2. Prior-round findings (explicit verdicts)

- **R1-1 (nit, require block mis-sorted) — FIXED (unchanged since round 2).**
  No `composer.json` delta since `d00e0ed`; symfony-scoped keys re-verified `SORTED`,
  `strcmp(dependency-injection, deprecation-contracts)` = `-13`. (Whole-block comparison
  prints `NOT-SORTED` only because composer keeps the `php`/`ext-*` platform requires
  first — pre-existing convention, matches `origin/master`, out of scope.)
- **R1-2 (low, no test pins the matrix `sed` exclusion) — STILL PRESENT, deliberately
  deferred by main-session decision (not a regression).** No workflow-pinning test in the
  delta; both `sed` steps unchanged (`:150-155`, `:211-216`). Follow-up GitHub issue.
- **R1-3 (low, `Symfony\Contracts\*` still transitive) — PARTIALLY FIXED, as decided
  (unchanged since round 2).** CHANGELOG "three Symfony packages" wording intact
  (`CHANGELOG.md:51`); 8 `src/` files still import `Symfony\Contracts\*` (7
  EventDispatcher + 1 `ResetInterface`), deferred to the `composer-require-checker`
  follow-up. (This round is, ironically, the deferred class paying rent in reverse:
  the *declared* dep is what broke 6.4 legs — but via test-only API drift, not resolution.)
- **R2-1 (nit, proof-doc staleness) — FIXED (unchanged since round 2).**
  `code-decision-1.md:22-24` order matches `composer.json:37-38`; S-1's `:211-216` still
  exactly covers the second `sed` step (re-verified against the current file).
- **R-CI-1 (high, 6.4 legs fail: no `$tempFileObject`, `LogicException` from
  `setChunkSize(0)`) — FIXED by this delta (pending the matrix re-run as oracle).**
  5 feature-detection skips + 1 widened exception expectation, all verified below; no
  `src/` change needed since `BinaryFileResponseReflector::ensurePropertiesCached()`
  already catches `ReflectionException` → `null` (re-read `src/.../BinaryFileResponseReflector.php:62-74`).

## 3. Findings (new this round)

**No new findings.** Every check in §4 passed; the two judgment calls in `code-decision-2.md`
(fixture left unguarded, no constraint narrowing) are both correct — see §4.

## 4. Verifications (with evidence)

- **Skip guards skip on 6.4 and no-op on 7+.**
  `property_exists()` is visibility-agnostic and autoloads — sound for a `protected` prop.
  Installed 8.1 probe: `property_exists(BinaryFileResponse::class, 'tempFileObject')` =
  `true` (prop exists, `protected`) → guards no-op: 0 new skips in the 40 strategy +
  48 reflector/streamed unit tests, and the E2E temp-file test ran full (3 assertions).
  6.4 half verified against `symfony/http-foundation` v6.4.0 source: the class declares
  only `$file/$offset/$maxlen/$deleteFileAfterSend/$chunkSize` — no `tempFileObject` →
  all 5 guards skip. All 4 unit `getProperty('tempFileObject')` sites sit inside guarded
  methods (`ReflectorTest:49`, `StrategyTest:111/:870/:992`), guards first statement
  before any reflection. Wording is deliberately version-agnostic (no unverified "7.x" claim).
- **`LogicException` + message-fragment assertion sound on both versions.**
  PHPUnit 10 `expectException()` matches via `instanceof`, and
  `is_subclass_of(InvalidArgumentException::class, LogicException::class)` = `true`
  (probed); 8.1 probe: `setChunkSize(0)` throws `InvalidArgumentException: The chunk size
  of a BinaryFileResponse cannot be less than 1.` 6.4.0 source: `throw new \LogicException(
  'The chunk size of a BinaryFileResponse cannot be less than 1 or greater than PHP_INT_MAX.')`.
  Fragment `'cannot be less than 1'` is a substring of both; `expectExceptionMessage()` is
  substring match. Residual weakening (bare `LogicException` on 6.4) is pinned by the
  fragment, and the test body does nothing else that throws. Note `StreamedBinaryFileResponse`
  is an empty `BinaryFileResponse` subclass (`src/Protocol/Http/Response/StreamedBinaryFileResponse.php:9`),
  so the Symfony messages apply verbatim.
- **E2E skip validity (test process vs daemon).** `composer test`/`test:coverage` run
  `restart -d` + `phpunit` in the same checkout, and CI `tests.yaml` installs + tests in
  the same job — daemon and test process share one vendor dir, so the test-process
  `property_exists()` reflects the daemon's Symfony. `response_test_temp_file` is referenced
  only by the skipping test (`ResponseTest.php:148` + route def) → the fixture's
  `getProperty('tempFileObject')` (`ResponseTestController.php:90`) never executes on 6.4.
- **Fixture correctly left unguarded.** Agree with `code-decision-2.md` rejection #2: with
  the route unreachable on 6.4, a 501 fallback would be dead-on-modern-Symfony code in a
  test-only controller. Likewise rejection #3 (no `^7.0` narrowing — would drop claimed 6.4
  support) and #1 (`setFile(SplTempFileObject)` — 6.4 behavior unverifiable locally).
- **PSR-12 / static analysis.** `php-cs-fixer fix --dry-run` repo-wide: 0 of 256 files to
  fix; `vendor/bin/phpstan`: no errors. New 141-char skip lines match pre-existing long lines
  in the same files (`ResponseTest.php:93` is 134 chars) — the ruleset has no line-length rule.
- **No other 6.4-sensitive API usage (cheap sweep).** All `src/` Symfony imports are
  long-standing (Config/Console/DI/`EventSubscriberInterface`/Request/`StreamedResponse`/
  `BinaryFileResponse`/`UploadedFile`/`SuspiciousOperationException`/HttpKernel/Runtime/
  Contracts) — no 7+-only classes. `grep getPayload|StreamedJson|->toArray(|getEnum(` in
  `src/` hits only the reflector's own `getTempFileObject` (6.4-safe via the
  `ReflectionException` catch). In tests: `offset`/`maxlen`/`deleteFileAfterSend` confirmed
  present in the 6.4.0 source → remaining reflection sites safe;
  `deleteFileAfterSend()`/`setContent()`/`sendContent()`/`prepare()` all exist on 6.4 with
  compatible behavior for these tests; `StreamedJsonResponse` already `class_exists`-guarded.
  A hidden second incompatibility behind the 6 fixed failures cannot be ruled out locally —
  the matrix re-run is the oracle (as `code-decision-2.md` notes); the identical failure set
  across all four 6.4 legs suggests a single root cause.
- **Targeted test runs (full `composer test` passed pre-fix per main session; delta is
  test-only so a targeted run suffices).** `BinaryFileResponseReflectorTest|StreamedBinaryFileResponseTest`:
  OK (48 tests, 57 assertions, 3 pre-existing `symfony/mime` skips);
  `BinaryFileResponseStrategyTest`: OK (40 tests, 124 assertions);
  `ResponseTest` E2E against a live daemon + `--filter testBinaryFileResponseWithTempFileObject`:
  OK (1 test, 3 assertions; daemon stopped after, ports down).
  `composer validate --strict` → valid; `php bin/check-changelog.php` → OK.

## 5. Candidate docs/helpers entries

- **C-3 (proposed, for retro): pinning a transitive dep to a direct require can *lower* the
  tested version on matrix legs — the suite may never have run against the floor.**
  Suggested tags: `ci,tests`. Trigger: "declaring a previously-transitive package as a
  direct require while a matrix rewrites constraints". One paragraph: declaring
  `symfony/http-foundation` pinned it to `6.4.*` on the 6.4 legs (previously floated to
  7.x), exposing 7-only API assumptions (`BinaryFileResponse::$tempFileObject`,
  `InvalidArgumentException` from `setChunkSize()`) that every local run missed because
  the installed tree was 8.1. Proving rewritten constraints *resolve* is not proving the
  suite *passes* at the floor — run at least the touching tests with the lowest supported
  minor installed before opening the PR. (Rounds 1–3 + R-CI-1 of #588; fix: version-agnostic
  `property_exists()` skips + parent-exception expectations.)
