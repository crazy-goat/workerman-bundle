# Findings (review) — Issue #588: composer.json dependency hygiene

One entry per review finding: file:line, what is wrong, severity, and what happened to it.
(Round 1 is the first review round — no prior-round entries to carry over.)

## Round 1

- **R1-1 — `composer.json:37-38`, require block mis-sorted (nit).**
  `symfony/deprecation-contracts` is listed before `symfony/dependency-injection`, but
  byte-wise `dependency-injection` sorts first (`dep-e…` < `dep-r…`; PHP `sort()` confirms
  `NOT-SORTED`). Violates the file's `"sort-packages": true` convention and contradicts
  `code-decision-1.md`'s "kept alphabetically sorted" claim. No functional impact
  (`composer validate --strict` is green — it does not check order). Status: **fixed**,
  swapped the two lines in main-session fix commit. Check that could catch it: `ComposerConfigTest`
  sorted-keys assertion or `composer normalize --dry-run`.
- **R1-2 — `tests/GithubWorkflowsTest.php` (missing), no test pins the matrix `sed`
  exclusion against `composer.json` (low).** Both `Update Symfony constraints` steps in
  `.github/workflows/tests.yaml` embed a regex+exclusion that must track every `symfony/*`
  line's versioning scheme; drift surfaces only as red matrix legs post-push (any future
  `*-contracts` require without an exclusion breaks all 9 legs — the S-1 shape). Wanted:
  a `GithubWorkflowsTest` test replaying the rewrite against `composer.json` and asserting
  every non-framework-line `symfony/*` entry is excluded. Status: **deliberately not fixed
  in this PR** (main session): first-seen class, low risk, new test infrastructure out of
  scope for this chore — will be offered as a follow-up GitHub issue in step 14
  (class first seen here → reported, not written, per review step 6).
- **R1-3 — `composer.json` (`require`, missing `symfony/event-dispatcher-contracts` /
  `symfony/service-contracts`), `Symfony\Contracts\*` imports still transitive (low).**
  7 `src/` files import `Symfony\Contracts\EventDispatcher\*` and 1 imports
  `Symfony\Contracts\Service\ResetInterface`; the diff's CHANGELOG claim "Declared the
  Symfony packages the bundle imports directly" is slightly overstated. No functional
  defect (both resolve reliably via `event-dispatcher` / `dependency-injection`).
  Status: **partially fixed** (main session): CHANGELOG wording narrowed to "three Symfony
  packages" to avoid overclaim; remainder deferred to the planned `composer-require-checker`
  follow-up from the
  issue's acceptance criteria; declaring them later requires extending the `tests.yaml`
  `sed` exclusion (both are 2.x/3.x-versioned).

## Round 2 (@ `d00e0ed`)

- **R1-1 — verdict: FIXED.** The two lines are swapped (`dependency-injection` at
  `composer.json:37`, `deprecation-contracts` at `:38`); re-verified `SORTED` with a
  correct comparison (`array_values` + `sort(SORT_STRING)`, `strcmp` = `-13`). (Round 1's
  one-liner had a key-preservation flaw that always printed `NOT-SORTED`, but its
  byte-order reasoning was independently correct — the finding was real.)
- **R1-2 — verdict: STILL PRESENT, deliberately deferred (main-session decision).**
  No `tests/` file in the diff; the drift-only-surfaces-in-CI shape is unchanged.
  Follow-up GitHub issue, out of scope for this chore.
- **R1-3 — verdict: PARTIALLY FIXED, as decided.** CHANGELOG wording narrowed to
  "three Symfony packages" (`CHANGELOG.md:51`) — overclaim gone. Contracts remainder
  still transitive by design, deferred to the `composer-require-checker` follow-up.
- **R2-1 — `docs/proof_of_work/0588-composer-json-deps/code-decision-1.md:22-24` and
  `findings-coder.md` S-1, proof docs contradict the fixed tree (nit, docs-only).**
  `code-decision-1.md` still lists the pre-fix order ("deprecation-contracts,
  dependency-injection"); S-1 cites the pre-change second-step lines (`:209-212`,
  now `:211-216`). No shipped-code impact; no plausible automated check (prose
  staleness in scratch docs). Status: **fixed** (main session): swapped the two names in
  code-decision-1.md and updated S-1 line refs to `:211-216`.

## Round 3 (@ `2dd8600`)

- **R1-1 — verdict: FIXED (unchanged since round 2).** No `composer.json` delta
  since `d00e0ed`; order still `dependency-injection` (`:37`) before
  `deprecation-contracts` (`:38`), re-verified `SORTED` (`strcmp` = `-13`).
- **R1-2 — verdict: STILL PRESENT, deliberately deferred (main-session decision).**
  No `tests/` file in the diff; the drift-only-surfaces-in-CI shape is unchanged.
  Follow-up GitHub issue, out of scope for this chore.
- **R1-3 — verdict: PARTIALLY FIXED, as decided.** CHANGELOG wording narrowed to
  "three Symfony packages" (`CHANGELOG.md:51`); contracts remainder still
  transitive by design (7 + 1 files), deferred to the `composer-require-checker`
  follow-up.
- **R2-1 — verdict: FIXED by `2dd8600`.** `code-decision-1.md:22-24` now lists
  "dependency-injection, deprecation-contracts" (matches `composer.json:37-38`);
  S-1 now cites `:211-216`, which exactly covers the second `sed` step
  (verified against the current file). No remaining proof-doc staleness.
- **New findings: none.** Shipped-code diff byte-identical to round 2
  (proof-docs-only delta); full sweep green (71 tests, 329 assertions;
  `composer validate --strict` exit 0; `check-changelog.php` OK).

## CI round (PR #806, run 34403956293) — recorded by main session per step 11

- **R-CI-1 — all four Symfony 6.4 matrix legs fail: 4 errors + 2 failures,
  `BinaryFileResponse::$tempFileObject` does not exist on 6.4 (high,
  merge-blocking, escaped defect).** Declaring `symfony/http-foundation`
  as a direct require pins it to `6.4.*` on the 6.4 legs (previously it
  floated transitively to 7.x/8.x — the 6.4 leg log shows e.g.
  `symfony/error-handler v7.4.17` still floating). The suite had never
  actually run against 6.4's `BinaryFileResponse`, which lacks the
  `$tempFileObject` property (4 errors: reflection `getProperty()` in
  `BinaryFileResponseReflectorTest::testGetTempFileObjectReturnsObjectWhenSet`,
  `BinaryFileResponseStrategyTest::testConvertHandlesTempFileObject`,
  `...::testHeadRequestWithTempFileDoesNotReadBodyAndEmitsTempSize`,
  `...::testHeadRequestWithTempFileFallsBackToFstatWhenContentLengthAbsent`;
  1 failure: E2E `ResponseTest::testBinaryFileResponseWithTempFileObject`
  500s because the `ResponseTestController::tempFileResponse()` fixture
  uses the same reflection) and throws `LogicException` (not
  `InvalidArgumentException`) from `setChunkSize(0)` (1 failure:
  `StreamedBinaryFileResponseTest::testSetChunkSizeThrowsExceptionForInvalidValues`).
  `src/` needs no change — `BinaryFileResponseReflector` already catches
  `ReflectionException` and returns `null` for absent properties, so the
  temp-file path is correctly dead on 6.4. Why rounds 1–3 missed it:
  every local verification ran against the installed Symfony 8.1 tree;
  the coder proved the rewritten constraints *resolve* on all legs but
  never *ran* the suite against the lowest supported minor. Check that
  could have caught it: running the suite (or at least the
  http-foundation-touching tests) with `symfony/http-foundation:6.4.*`
  installed before opening the PR. Status: **fixed** (main session):
  feature-detection skip guards (`property_exists(...,
  'tempFileObject')`, following the `StreamedJsonResponse` precedent in
  `SymfonyControllerTest`) in the 4 unit tests + the E2E test, and
  `LogicException` (+ shared message fragment) expectation for
  `setChunkSize(0)` since `InvalidArgumentException extends
  LogicException`. See `code-decision-2.md`.

## Round 4 (CI-fix round, uncommitted delta on top of `0912280`)

- **R1-1 — verdict: FIXED (unchanged since round 2).** No `composer.json`
  delta since `d00e0ed`; symfony-scoped keys re-verified `SORTED`
  (`strcmp` = `-13`).
- **R1-2 — verdict: STILL PRESENT, deliberately deferred (main-session decision).**
  No workflow-pinning test in the delta; both `sed` steps unchanged
  (`:150-155`, `:211-216`). Follow-up GitHub issue, out of scope.
- **R1-3 — verdict: PARTIALLY FIXED, as decided.** CHANGELOG "three Symfony
  packages" wording intact (`CHANGELOG.md:51`); 8 `src/` files still import
  `Symfony\Contracts\*`, deferred to the `composer-require-checker` follow-up.
- **R2-1 — verdict: FIXED (unchanged since round 2).**
  `code-decision-1.md:22-24` matches `composer.json:37-38`; S-1's `:211-216`
  re-verified against the current `tests.yaml`.
- **R-CI-1 — verdict: FIXED by this delta (pending the matrix re-run as oracle).**
  All 5 `property_exists(... 'tempFileObject')` skips verified to no-op on 7+
  (true on installed 8.1; 0 new skips locally) and skip on 6.4 (prop absent in
  v6.4.0 source); `LogicException` + `'cannot be less than 1'` sound on both
  (`instanceof` semantics, fragment present in both messages — see `review-4.md`
  §4). E2E skip valid (daemon shares the vendor dir; route single-caller);
  fixture correctly unguarded; no other 6.4-sensitive API found in the sweep.
- **New findings: none.** Verdict **clean**. Targeted runs green (48 + 40 unit,
  E2E temp-file test 3 assertions; `php-cs-fixer` 0/256, `phpstan` no errors,
  `composer validate --strict` valid, `check-changelog.php` OK). Full `composer
  test` passed pre-fix; delta is test-only so a targeted run suffices.
