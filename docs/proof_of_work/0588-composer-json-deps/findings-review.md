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
