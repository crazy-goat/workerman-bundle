# Review — round 1 (issue #667)

**Branch:** `fix/issue-667-periodicaltrigger-with-zero-or-negative`
**PR:** #698
**Diff:** `git diff master...HEAD` — `src/Scheduler/Trigger/PeriodicalTrigger.php` + 3 test files

## Earlier findings

No `findings-review.md` existed prior to this round — this is the first review pass.

The coder's own `findings-coder.md` contains 7 observations. I reviewed each:

| # | Coder finding | Verdict | Evidence |
|---|---|---|---|
| 1 | `SchedulerWorker` treats `schedule => 0` as "no schedule" via `empty()` | Not a review finding | Pre-existing safety net at `SchedulerWorker.php:54`. The coder correctly switched the worker-level test to `'PT0S'` to exercise the constructor path. No code change needed. |
| 2 | `getNextRunDate()` null guard is now dead code | Not a review finding | `PeriodicalTrigger.php:73-75` — `$date > $now ? $date : null` can no longer return null for constructed instances. Deliberately kept as contract documentation. Harmless. |
| 3 | `DateInterval` accepts per-component negative values; `add()` honours `invert` | Informational | Verified empirically: `'-1 day +25 hours'` → +1h forward; `invert=1` → backwards. The add-based check handles both correctly. |
| 4 | Sub-second test at `SchedulerWorkerTest.php:602` with `f = 0.5` | Not a review finding | Still passes — `add()` moves +0.5s. The new validation does not reject it. |
| 5 | `SchedulerWorker` discards exception message in catch | Still present, out of scope | `SchedulerWorker.php:62` — `catch (\InvalidArgumentException)` without variable. Would help diagnosis but not part of #667. |
| 6 | Double-wrapping of `InvalidTriggerException` messages | Still present, cosmetic | Pre-existing pattern. The positivity check throws inside the `try`, so the `catch (\Throwable)` wraps it: `Invalid interval "0": Interval must be a positive duration`. Tests use substring match so they pass. |
| 7 | No xdebug/pcov locally | Environmental | Not a code issue. All new lines are covered by the added tests. |

## Verdict

The fix is **correct and well-designed**. The add-based positivity check (`$now->add($dateInterval) <= $now`) is the right predicate — it matches exactly what `getNextRunDate()` uses (`$date > $now`), handles mixed-sign intervals, inverted `DateInterval`, and sub-second fractions correctly. Constructor-level validation is the single convergence point (all input forms normalize to `\DateInterval` before the check), so `TriggerFactory` and `SchedulerWorker` need no changes.

All 9 new test cases fail against master and pass on the fix branch — they lock in the fix, not the bug.

No documented decisions in `docs/helpers/decisions.md` are violated. The relevant entries checked:
- DEC-003 (worker-level sweeper for timeouts) — unrelated to scheduler triggers.
- FAQ-020 (unwrap `JitterTrigger` before type-checking) — not relevant to this change.
- FAQ-021 (`DateInterval` has no fractional-second parser) — confirmed and respected; the positivity check uses `add()` which honours `f`.

## New findings

| ID | file:line | description | severity |
|----|-----------|-------------|----------|
| R1-1 | `CHANGELOG.md` (Unreleased / Fixed) | No CHANGELOG entry for #667. The `### Fixed` section under `[Unreleased]` documents every other behavioral fix with an issue reference and a paragraph. This fix changes behavior (previously-accepted zero/negative intervals now throw `InvalidTriggerException` at construction), so it belongs there. `ChangelogStructureTest` checks structure and issue-reference presence on existing entries but does not enforce that every issue gets an entry — this is a manual-convention gap. | medium |
| R1-2 | `tests/PeriodicalTriggerTest.php:83-96` | No positive test for mixed-sign intervals. The code-decision doc explicitly calls out `'-1 day +25 hours'` (nets +1h forward) as a valid case the add-based check must accept. The `nonPositiveIntervalProvider` covers all rejection cases thoroughly, but there is no test asserting a mixed-sign interval is *accepted*. Without it, a future refactor to a field-wise check could silently break this edge case. | low |

## What I checked and found clean

- **Type correctness / PHPStan level 8:** `$dateInterval` and `$description` are assigned in every branch before use. The positivity check operates on a guaranteed `\DateInterval`. No nullable or union-type issues.
- **Exception hierarchy:** `InvalidTriggerException` → `SchedulerException` → `\InvalidArgumentException` → `WorkermanExceptionInterface`. `SchedulerWorker`'s `catch (\InvalidArgumentException)` catches it. `TriggerFactoryTest` correctly expects `\InvalidArgumentException`; `PeriodicalTriggerTest` expects the more specific `InvalidTriggerException`.
- **Edge cases empirically verified:** `0 seconds`, `-1 second`, `PT0S`, inverted `P1D`, `-1 day +25 hours` (forward), `PT0S` + `f=0.5` (forward) — all behave as expected under `DateTimeImmutable::add()`.
- **DST/timezone:** `add()` operates in absolute time; a positive calendar interval always moves forward (23/24/25 hours for `P1D` across DST). No false rejection possible.
- **Regression test efficacy:** All 9 new test cases fail against `master` (verified by checking out master's `PeriodicalTrigger.php` and running the tests — 9 failures) and pass on the fix branch (9/9 OK). They lock in the fix.
- **`empty()` safety net:** `SchedulerWorker.php:54` catches `schedule => 0` (int) before it reaches `TriggerFactory`. The worker test correctly uses `'PT0S'` to bypass this and exercise the constructor.
- **PSR-12 / formatting:** Code is clean; `composer lint` (php-cs-fixer, PHPStan, Rector, kb-lint) reportedly passes.
- **`TriggerFactory` routing:** `create(0)` → not string, not `\DateTimeImmutable`, not `\DateInterval` → `default => new PeriodicalTrigger(0)` → rejected. `create('0')` → string, not ATOM datetime, `CronExpression::isValidExpression('0')` → true → `CronExpressionTrigger` (correctly different path — `'0'` is a valid cron expression meaning "every minute").
- **No modifications to `TriggerFactory` or `SchedulerWorker` needed:** The constructor is the single convergence point. The coder's decision to not add duplicate validation is correct.

## Candidate knowledge-base entry

**Title:** `PeriodicalTrigger` rejects non-positive intervals at construction time
**Tags:** `scheduler`, `date-time`, `validation`
**Trigger:** editing `PeriodicalTrigger` constructor or trigger validation logic

`PeriodicalTrigger::__construct()` validates that the interval moves the clock forward by checking `$now->add($dateInterval) <= $now` after normalizing every input form (int, ISO 8601 `P…` string, relative date string, `\DateInterval` instance) to a single `\DateInterval`. This is the same predicate `getNextRunDate()` uses (`$date > $now`), evaluated at construction time. A field-wise check would wrongly reject mixed-sign intervals like `'-1 day +25 hours'` (nets +1h forward); the add-based check is the only correct approach because it measures net time movement, including the `invert` flag and the fractional `f` property. `SchedulerWorker::onWorkerStart` catches the resulting `\InvalidArgumentException` and logs `Task "…" skipped. Trigger "…" is incorrect`, so misconfiguration fails fast at startup instead of silently producing a dead task. Note: `SchedulerWorker`'s `empty($serviceConfig['schedule'])` guard catches int `0` before it reaches `TriggerFactory`, so a worker-level regression test must use a non-empty zero-length form (e.g. `'PT0S'`) to exercise the constructor path.

## Gaps in validation

- No automated check enforces that every issue gets a CHANGELOG entry. `ChangelogStructureTest` checks structure and issue-reference presence on existing entries only.
- No automated check would catch a future regression to a field-wise positivity check breaking mixed-sign intervals — a unit test for `'-1 day +25 hours'` would be the guard.
