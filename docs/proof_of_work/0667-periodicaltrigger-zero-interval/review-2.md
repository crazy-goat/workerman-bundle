# Review — round 2 (issue #667)

**Branch:** `fix/issue-667-periodicaltrigger-with-zero-or-negative`
**PR:** #698
**Diff since round 1:** `git diff edcf65e..HEAD` — CHANGELOG.md entry, `testMixedSignIntervalIsAccepted` test, updated findings-review.md

## Earlier findings — round 1 status

### R1-1 — No CHANGELOG entry for #667 — FIXED ✓

**Evidence:** `CHANGELOG.md` lines 59–65 now contain a multi-paragraph entry under `[Unreleased] > ### Fixed`:

> `PeriodicalTrigger` with a zero or negative interval (int `0`, negative
> int, `PT0S`, `0 seconds`, `-1 second`, empty/`invert`-ed `DateInterval`)
> is now rejected in the constructor with `InvalidTriggerException` instead
> of silently never scheduling the task while the startup log claimed it
> was "scheduled" — misconfiguration now fails fast at startup
> ([#667](https://github.com/crazy-goat/workerman-bundle/issues/667))

- **Keep a Changelog compliance:** Entry is under `### Fixed` in `[Unreleased]` — correct category for a bug fix. Has an issue reference link `[#667]` in the standard GitHub link format, consistent with every other entry in the section. ✓
- **Accuracy:** The description matches the actual code change — the constructor now throws `InvalidTriggerException` for zero/negative intervals, and `SchedulerWorker` catches `\InvalidArgumentException` (parent of `InvalidTriggerException`) and logs `Task "…" skipped. Trigger "…" is incorrect`. The listed rejection cases (int 0, negative int, PT0S, 0 seconds, -1 second, empty/inverted DateInterval) all match the `nonPositiveIntervalProvider` test cases. ✓
- **Line length:** All lines ≤ 74 chars, consistent with other multi-line entries. ✓
- **`ChangelogStructureTest`:** Would validate this entry — it checks structure (one `[Unreleased]`, descending versions, no duplicate subheadings) and issue-reference presence on every entry. The `[#667]` link satisfies the issue-reference check. ✓

**Verdict: R1-1 is fully resolved.**

### R1-2 — No positive test for mixed-sign intervals — FIXED ✓

**Evidence:** `tests/PeriodicalTriggerTest.php:100–113` now contains `testMixedSignIntervalIsAccepted`:

```php
public function testMixedSignIntervalIsAccepted(): void
{
    $trigger = new PeriodicalTrigger('-1 day +25 hours');
    $now = new \DateTimeImmutable('2024-01-15 12:00:00');
    $nextRun = $trigger->getNextRunDate($now);
    $this->assertSame('every -1 day +25 hours', (string) $trigger);
    $this->assertInstanceOf(\DateTimeImmutable::class, $nextRun);
    $this->assertGreaterThan($now, $nextRun);
}
```

**Does the test verify net-positive mixed-sign interval behavior?** Yes:
- `-1 day +25 hours` creates a `\DateInterval` with `d=-1, h=25` (verified empirically: `invert=0`, net +1 hour forward).
- The constructor accepts it (no exception thrown).
- `getNextRunDate()` returns `2024-01-15 13:00:00` — exactly +1h from `2024-01-15 12:00:00`, confirming net forward movement.
- The description string `'every -1 day +25 hours'` matches the `sprintf('every %s', $interval)` format in the relative-string branch.

**Does the test fail against master?** No — and this is **correct by design**. On master, `PeriodicalTrigger` has no positivity check, so `'-1 day +25 hours'` is accepted, and `getNextRunDate()` returns a future date. The test passes on both master and the fix branch. This is a **regression guard** (its stated purpose in R1-2): it would fail only if a future refactor switched to a field-wise positivity check that wrongly rejects mixed-sign intervals. The 9 rejection tests from round 1 are the ones that fail against master and lock in the fix.

**Verdict: R1-2 is fully resolved.** The test is a proper guard against the specific regression class identified in round 1.

## Verdict

Both round-1 findings are properly resolved. The round-2 diff (CHANGELOG entry + mixed-sign test) is clean, accurate, and well-formed. No new issues found in the full branch diff (`master...HEAD`).

## New findings

No new findings.

## What I checked and found clean

- **CHANGELOG entry accuracy:** The entry lists exactly the rejection cases covered by `nonPositiveIntervalProvider` (int 0, negative int, PT0S, 0 seconds, -1 second, empty/inverted DateInterval). The exception type (`InvalidTriggerException`) and the behavioral description (rejected at constructor instead of silently never scheduling) match the code.
- **CHANGELOG format (Keep a Changelog):** Correct section (`### Fixed` under `[Unreleased]`), issue reference present, multi-line wrapping consistent with neighboring entries (e.g., #587, #565).
- **`ChangelogStructureTest` compatibility:** The entry has an issue reference link; the test validates structure and issue-reference presence, so this entry passes.
- **`testMixedSignIntervalIsAccepted` correctness:** Empirically verified that `DateInterval::createFromDateString('-1 day +25 hours')` produces `d=-1, h=25, invert=0`, and `DateTimeImmutable::add()` moves +1h forward. The test's assertions (description string, `DateTimeImmutable` instance, future date) all hold.
- **Test as regression guard:** The test would fail against a field-wise positivity check (which would see `d=-1` and reject), but passes against the add-based check. This is exactly the guard R1-2 asked for.
- **No documented decisions violated:** Checked FAQ-020 (unwrap JitterTrigger), FAQ-021 (DateInterval fractional seconds), FAQ-022 (mock date evaluation), DEC-003 (worker-level sweeper), DEC-008 (lint canonical entry), DEC-009 (single KB writer). None are relevant to or violated by this change.
- **Full branch diff re-checked:** `src/Scheduler/Trigger/PeriodicalTrigger.php` — the add-based positivity check (`$now->add($dateInterval) <= $now`) is the same predicate as `getNextRunDate()`'s `$date > $now`, evaluated at construction. The refactoring to delay `$this->interval`/`$this->description` assignment until after the check is correct — all branches set both `$dateInterval` and `$description` before the check, and assignment happens once after. No type issues (PHPStan level 8 compatible).
- **No double-message concern for the new test:** `testMixedSignIntervalIsAccepted` does not expect an exception, so the `catch (\Throwable)` wrapping in the constructor is irrelevant to it. The 9 rejection tests use substring matching (`'positive duration'`) which works through the double-wrapping (`Invalid interval "0": Interval must be a positive duration`).

## Candidate knowledge-base entries

None — the candidate from round 1 (PeriodicalTrigger rejects non-positive intervals at construction time) is still the right one and already proposed. No new candidates from round 2.

## Gaps in validation

- No automated check enforces that every closed issue gets a CHANGELOG entry (`ChangelogStructureTest` checks structure and issue-reference presence on existing entries only). This is the same gap noted in round 1 — not a new finding.
- `testMixedSignIntervalIsAccepted` passes on master, so CI would not flag its accidental removal. This is inherent to guard tests (they protect against future regressions, not current bugs). Not a finding.
