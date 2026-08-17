# Review Round 4 — issue #597

**Branch:** `process/issue-597-workflow-runs-on-pull-request-only-maste`
**Reviewer:** review-critical (round 4, escalation)
**Date:** 2026-08-17
**Base:** `origin/master`
**HEAD:** `e251930`

## Scope

Round 4 is an escalation after the step-7 local gate (`composer test`)
failed post-round-3. The failure was in
`tests/CoverageCiGateTest::testCoverageGateRunsOnceOnLowestMatrixLegOnly`,
which asserts `substr_count($workflow, 'run: composer coverage:check') === 1`.
The new `tests-scheduled` job had duplicated the coverage gate → count 2.
Commit `e251930` fixed it by stripping coverage machinery from
`tests-scheduled` (plain `composer test`; `coverage: pcov`, the
`pcov.directory` ini pair, the "Run tests with coverage" step, and the
"Check coverage threshold" step removed). The gate stays in the `tests`
job's 8.2/6.4 leg.

This round: (1) dispositions F-7 against the fix commit, (2) hunts for
sibling tests/tooling that pin coverage strings, (3) verifies the
scheduled run still satisfies the issue's intent without the coverage
gate, (4) re-verifies PR/push/workflow_dispatch paths are unchanged from
round 3, (5) runs the structural verification tests.

## F-7 disposition — FIXED (confirmed)

**Finding:** `tests-scheduled` duplicated the coverage gate, making
`substr_count($workflow, 'run: composer coverage:check') === 2`, failing
`CoverageCiGateTest::testCoverageGateRunsOnceOnLowestMatrixLegOnly`.

**Fix commit:** `e251930` — diff to `.github/workflows/tests.yaml`:

```diff
-          coverage: pcov
-          ini-values: pcov.directory=src,phar.readonly=0
+          ini-values: phar.readonly=0
...
-      - name: Run tests with coverage
-        run: composer test:coverage
-
-      - name: Check coverage threshold
-        run: composer coverage:check
+      - name: Run tests
+        run: composer test
```

**Evidence (current YAML, HEAD `e251930`):**

| Assertion | Expected | Actual | Pass |
|---|---|---|---|
| `substr_count($workflow, 'run: composer coverage:check')` | 1 | 1 | ✓ |
| `assertStringContainsString('coverage: pcov', $workflow)` | present | present (in `tests` job) | ✓ |
| `assertStringContainsString('composer test:coverage', $workflow)` | present | present (in `tests` job) | ✓ |
| `assertStringContainsString('composer coverage:check', $workflow)` | present | present (in `tests` job) | ✓ |
| `assertStringNotContainsString('bin/check-coverage.php', $workflow)` | absent | absent | ✓ |
| Threshold step regex (8.2/6.4 leg `if:`) | match | match | ✓ |
| Upload step regex (`if: always()`) | match | match | ✓ |

The `tests-scheduled` job now runs plain `composer test` (no pcov driver,
no coverage clover, no threshold check). The `tests` job retains all
coverage machinery on its 8.2/6.4 leg. The `CoverageCiGateTest` suite
(8 tests) and `GithubWorkflowsTest` suite (11 tests) both pass: 21 tests,
68 assertions, 0 failures.

**Verdict:** F-7 is **fixed**. The fix is the smallest safe fix direction
prescribed in the finding — strip coverage machinery from `tests-scheduled`,
do not relax the `substr_count === 1` gate.

## Sibling sweep — no missed siblings

### Methodology

Grepped `tests/`, `bin/`, and `docs/` for every coverage-related string
that could be pinned against the workflow YAML: `test:coverage`,
`coverage:check`, `coverage: pcov`, `pcov`, `tests-scheduled`,
`pcov.directory`, `Run tests with coverage`, `Check coverage threshold`,
`Upload coverage report`, `Sanitize artifact`.

### Results

**Files that read the workflow YAML (`.github/workflows/tests.yaml`):**

1. `tests/CoverageCiGateTest.php` — 8 tests pinning coverage strings:
   - `testCiWorkflowRunsCoverageAndThresholdCheck`: `assertStringContainsString`
     for `coverage: pcov`, `composer test:coverage`, `composer coverage:check`
     (presence-only, not count — all still present in the `tests` job)
   - `testCoverageGateRunsOnceOnLowestMatrixLegOnly`: `substr_count === 1`
     for `run: composer coverage:check` (the assertion F-7 tripped — now
     passes) + regex for the 8.2/6.4 leg `if:` condition
   - `testCoverageReportUploadRunsEvenWhenGateFails`: regex for upload
     step `if: always()`
   - `testThresholdIsDefinedOnlyInComposerScript`: asserts
     `bin/check-coverage.php` is NOT in the workflow
   - `testComposerCoverageCheckDefinesNonZeroThreshold`: reads
     `composer.json`, not the workflow
   - `testCoverageScriptExistsAndIsExecutable`: reads
     `bin/check-coverage.php`, not the workflow

2. `tests/GithubWorkflowsTest.php` — 11 tests pinning job structure,
   triggers, concurrency, matrix trimming, benchmark skip, issue opener.
   No coverage-string assertions.

**Files that mention coverage strings but do NOT read the workflow:**

- `tests/CheckCoverageGateTest.php` — tests `bin/check-coverage.php`
  behavior (Clover parsing, threshold exit codes). Does not read
  `tests.yaml`.
- `tests/KnowledgeBase/KbLintScriptTest.php:389` — references
  `CoverageCiGateTest.php` as a gate name in a KB-lint fixture. Does not
  read the workflow YAML.
- `bin/check-coverage.php` — the coverage checker script itself.
- `bin/README.md` — documentation about `check-coverage.php`.
- `bin/wait-for-ports.php:10` — comment mentioning `composer test:coverage`
  scripts. Not workflow-related.
- `docs/proof_of_work/0691-*` and `0688-*` — historical proof-of-work for
  the coverage checker. Not live tests.

**Count assertions on coverage strings (the F-7 defect class):**

Only one: `CoverageCiGateTest.php:26` —
`substr_count($workflow, 'run: composer coverage:check')`. No test
asserts a count on `coverage: pcov`, `composer test:coverage`,
`pcov.directory`, or any other coverage string. The `assertStringContainsString`
checks are presence-only and pass as long as the `tests` job retains
coverage machinery.

**Verdict:** No missed siblings. The only count assertion on a coverage
string in the entire codebase is the one F-7 tripped. The fix resolves it
without introducing a new count mismatch on any other string.

## Schedule-intent verification — YES, the scheduled run still does its job

### What the scheduled run does (post-fix, at HEAD `e251930`)

| Job | Runs on schedule? | What it does |
|---|---|---|
| `lint` | yes | `composer validate --strict` + `composer audit` + `composer lint` |
| `tests` | no (`if: github.event_name != 'schedule'`) | skipped |
| `tests-scheduled` | yes (`if: github.event_name == 'schedule'`) | single 8.2/6.4 leg, plain `composer test` |
| `benchmark` | no (`if: github.event_name != 'schedule'`) | skipped |
| `ci` | yes (`if: always()`) | OR-gate: `lint` success + either `tests` or `tests-scheduled` success; issue opener on failure |

### Issue intent

Issue #597 asked for the workflow to also run on push to master and on a
weekly schedule (not just pull requests). The scheduled run's purpose is
drift detection: catch new `composer audit` advisories, dependency drift
in unpinned Symfony ranges, and a broken master — all signals that
surface between merges when nobody is watching.

### Does removing the coverage gate from the scheduled run matter?

**No.** The coverage gate is a merge-quality signal, not a drift-detection
signal. It runs on every PR and every push to master — the events where
coverage can change. The scheduled run is a weekly check on an otherwise
quiescent branch; no code has changed since the last PR/push, so coverage
cannot have drifted. The threshold is stable (the `tests` job comment
says "per-leg coverage is stable within ~0.1pp"), so a weekly coverage
run provides no actionable signal that the PR/push gates don't already
provide. Running coverage on schedule would also require the pcov driver
(which was removed from the `tests-scheduled` setup step), adding
installation cost for zero signal.

The scheduled run's actual value — `composer audit` (advisories),
`composer test` (broken master / dependency drift), and the issue opener
(visible failure signal) — is fully intact without the coverage gate.

**Verdict:** YES, the scheduled run still satisfies the issue's intent.
Removing the coverage gate from the weekly run is correct: the gate is a
merge-quality signal that belongs on PR/push events, not on a
drift-detection schedule.

## PR / push / workflow_dispatch re-verification — unchanged from round 3

Commit `e251930` touched only the `tests-scheduled` job's steps (lines
141–160 of `tests.yaml`). The `tests` job, `benchmark` job, `ci` job,
`on:` triggers, and `concurrency:` block are byte-identical to the
round-3 state (commit `8a7d1e7`).

| Event | `tests` job | `benchmark` job | `tests-scheduled` job | `ci` gate | Concurrency |
|---|---|---|---|---|---|
| `pull_request` | runs (9 legs, with coverage) | runs | skipped (`if: == 'schedule'`) | lint + tests | cancels older |
| `push` (master) | runs (9 legs, with coverage) | runs | skipped | lint + tests | never cancels |
| `workflow_dispatch` | runs (9 legs, with coverage) | runs | skipped | lint + tests | never cancels |
| `schedule` | skipped (`if: != 'schedule'`) | skipped | runs (1 leg, no coverage) | lint + tests-scheduled | never cancels |

All four event paths are identical to round-3 conclusions. No path was
altered by the fix.

## Test execution

```
php -d phar.readonly=0 vendor/bin/phpunit --no-coverage --filter 'GithubWorkflowsTest|CoverageCiGateTest'
```

Result: **21 tests, 68 assertions, OK** (0 failures, 0 errors).

- `GithubWorkflowsTest`: 11 tests, 34 assertions
- `CoverageCiGateTest`: 8 tests, 34 assertions (including the
  `testCoverageGateRunsOnceOnLowestMatrixLegOnly` assertion that
  failed pre-fix)

The full `composer test` suite (2193 tests) was run by the main session
post-fix and reported 0 failures. This round's structural verification
confirms the two workflow-pinning test files pass independently.

## YAML structural verification

Parsed `.github/workflows/tests.yaml` with PyYAML `safe_load`:

- **5 jobs:** lint, tests, tests-scheduled, benchmark, ci
- **4 triggers:** pull_request, push (branches: [master]), schedule
  (cron: `23 5 * * 1`), workflow_dispatch
- **tests-scheduled steps:** Checkout, Setup PHP, Create var directory,
  Update Symfony constraints, Install dependencies, Run tests
  (`composer test`) — no coverage steps
- **tests job coverage step:** "Check coverage threshold" with
  `if: matrix.php-version == '8.2' && matrix.symfony-version == '6.4.*'`
  — single designated leg
- **ci job:** needs [lint, tests, benchmark, tests-scheduled],
  `if: always()`, OR-gate for tests/tests-scheduled, issue opener with
  `if: failure() && github.event_name == 'schedule'`

## Documentation accuracy (post-fix)

| Doc claim | YAML evidence | Match |
|---|---|---|
| `docs/workflow.md:432` — coverage floor "Runs on every trigger except `schedule`" | `tests` job `if: != 'schedule'`; `tests-scheduled` has no coverage steps | ✓ |
| `docs/workflow.md:433` — tests-scheduled runs "the same tests as a single representative leg" | `tests-scheduled` runs `composer test` (same PHPUnit suite as `composer test:coverage`, minus coverage instrumentation) | ✓ |
| `CONTRIBUTING.md:148` — 8.2/6.4 leg enforces coverage threshold; scheduled runs execute only 8.2/6.4 leg | `tests` job 8.2/6.4 `if:` gate; `tests-scheduled` single-leg matrix | ✓ |

No documentation inaccuracy introduced by the fix. The
`code-decision-1.md` last bullet ("The scheduled leg runs
`test:coverage` … and keeps the coverage gate") is now stale, but it is a
historical proof-of-work record written before F-7 was discovered — the
F-7 entry in `findings-review.md` documents the reversal. Not a finding.

## New findings

**None.** The fix is correct, minimal, and complete. No sibling tests
were missed. The scheduled run's intent is preserved. No gate is
weakened. No documentation is made inaccurate by the fix.

## Candidate knowledge-base entries

None new. The existing candidate entries from round 1 (concurrency group
discriminator; `if: always()` + OR-logic gate) remain the only
candidates; the main session decides whether they land.

## Remaining risk areas checked clean

- **Coverage gate (DEC-007):** `composer.json` unchanged; floor still
  `80.0`. `run: composer coverage:check` appears exactly once (the
  `tests` job's 8.2/6.4 leg). `CoverageCiGateTest` passes. ✓
- **Pre-push lint hook (DEC-008):** `bin/` unchanged. ✓
- **Security hardening (DEC-006):** no source code touched; `issues:
  write` is CI-scoped, documented, not a loosening. ✓
- **YAML validity:** parses (PyYAML `safe_load`); 5 jobs, 4 triggers. ✓
- **F-7 defect class (count assertions on coverage strings):** only one
  exists (`substr_count` on `run: composer coverage:check`); no siblings
  missed. ✓
- **PR/push/dispatch paths:** unchanged from round 3. ✓
- **Schedule path:** lint + single-leg tests + ci aggregator + issue
  opener; coverage correctly absent. ✓
- **Token/shell injection:** unchanged from round 3; `${{ needs.*.result }}`
  are runner-injected literals; no user input in the opener. ✓
- **No source, `bin/`, `composer.json`, or `phpunit.xml` changed by
  `e251930`.** Only `.github/workflows/tests.yaml` and
  `findings-review.md` were touched. ✓

## Remaining risk areas not fully verifiable from code review

- **Real scheduled run:** the first Monday 05:23 UTC run post-merge
  provides the real-world verification. Inherent to the change, not a
  finding.
- **CHANGELOG.md:** must have an `[Unreleased]` entry before the issue is
  closed. Delegated to the main session. Process step, not a code review
  finding.

## Final verdict

**No open findings remain.** F-7 is fixed and independently confirmed.
The sibling sweep found no missed tests. The scheduled run's intent is
preserved without the coverage gate. PR/push/dispatch paths are unchanged
from round 3. The structural verification tests pass (21 tests, 68
assertions). No gate is weakened.
