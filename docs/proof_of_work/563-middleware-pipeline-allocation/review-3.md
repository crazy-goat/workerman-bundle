# Review round 3 — Issue #563: index-based MiddlewareDispatcher

**Branch:** `perf/issue-563-middleware-pipeline-allocates-one-closur` (round-3 commit 42a896c)
**Files under review this round:** `tests/MiddlewareDispatcherTest.php` (one-line change).

## 0. Trigger verification

`review-critical` still applies: cumulative branch diff touches `src/Http`
and exceeds 200 changed lines.

## 1. docs/helpers consultation

Tag set unchanged from rounds 1–2 (`http`, `middleware`, `tests`, `phpstan`,
`ci`, `closures`). No new helpers entries since round 2. The round-2
candidate entry on minimum-version syntax checking (proposed, not yet
adopted) is exactly the class of this round's fix — if the retro adopts it,
F-5 becomes its reference incident alongside FAQ-029.

## 2. Open findings — disposition

- **F-5 (high, `new readonly class` at tests/MiddlewareDispatcherTest.php:130)**
  — **fixed** in 42a896c. The diff drops `readonly` from the anonymous
  short-circuit middleware; `grep -n readonly tests/MiddlewareDispatcherTest.php`
  now shows only `private readonly string $name` (line 254) — a readonly
  promoted constructor property, valid since PHP 8.1. I independently swept
  every file this branch touches (`src/Http/MiddlewareDispatcher.php`,
  `tests/MiddlewareDispatcherTest.php`, and the other branch-touched files)
  for other version-gated syntax — anonymous readonly classes, typed class
  constants, `#[\Override]`, property hooks / asymmetric visibility — and
  found none, corroborating the coder's claim in findings-coder.md. Local
  PHP 8.2 binary is not installed (`php@8.2 not found`), so a direct 8.2
  `php -l` was not possible; the only 8.3-only construct is gone and every
  remaining construct is 8.2-safe, so the 8.2 CI leg should now pass.
- **F-6 (nit, MiddlewarePipelineTest helper duplicates old composition)**
  — **still present, deliberately deferred.** The coder's round-3 note in
  findings-coder.md gives a sound reason: the helper tests the middleware
  *contract* independently of the dispatch implementation, and re-basing it
  on `MiddlewareDispatcher` would couple the contract test to the
  implementation under test. I accept the deferral; keeping F-6 open as a
  documented nit is correct, and `MiddlewareDispatcherTest` already pins the
  shipped path, so no coverage gap remains.

## 3. Verification performed this round

- `vendor/bin/phpunit --filter MiddlewareDispatcherTest --no-coverage`: **OK** (11 tests incl. FinalClass meta, 15 assertions).
- `vendor/bin/phpstan` (level 8): **clean** (255 files).
- `vendor/bin/php-cs-fixer fix --dry-run`: **clean**.
- Version-gated-syntax sweep of all branch-touched files: clean (see F-5).

## 4. New findings

None. The round-3 diff is a single keyword removal; it cannot regress
behavior (the anonymous class never had properties, so `readonly` was
decorative — runtime semantics identical).

## 5. Gate status

No gate lowered. All checks green; the only environment gap is the absence
of a local PHP 8.2 binary, mitigated by the CI 8.2 legs.

## Candidate knowledge-base entries

No new candidates this round. The round-2 candidate (*"New PHP syntax must
be checked against the minimum supported version — local PHP 8.5 hides
8.3-only constructs"*, tags `php82, tests, ci, phpstan, lint`) is now backed
by a completed incident (found in review, fixed pre-merge) and is the only
entry worth promoting; round-1 entries 1–2 stand unchanged.
