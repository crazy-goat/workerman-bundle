# Findings — review (issue #667)

## Round 1

### R1-1 | CHANGELOG.md (Unreleased / Fixed section) | No CHANGELOG entry for #667 | medium | fixed

The `### Fixed` section under `[Unreleased]` documents every other behavioral fix with a detailed paragraph and an issue reference. This fix changes user-visible behavior (previously-accepted zero/negative intervals now throw `InvalidTriggerException` at construction), so it belongs there. `ChangelogStructureTest` checks structure and issue-reference presence on existing entries but does not enforce that every issue gets an entry — a manual-convention gap. An automated check for this class would be a test that cross-references closed issues with CHANGELOG entries, though that is project-specific and hard to implement reliably.

Status: FIXED in commit (changelog entry added under `[Unreleased] > Fixed` with #667 reference).

### R1-2 | tests/PeriodicalTriggerTest.php:83-96 | No positive test for mixed-sign intervals | low | fixed

The `nonPositiveIntervalProvider` covers all rejection cases (int 0, negative int, `PT0S`, `0 seconds`, `-1 second`, zero `DateInterval`, inverted `DateInterval`). However, the code-decision doc explicitly calls out `'-1 day +25 hours'` (nets +1h forward) as a valid case the add-based check must accept. There is no test asserting this case is *accepted*. Without it, a future refactor to a field-wise check could silently break mixed-sign intervals. An automated check for this class: a unit test asserting `new PeriodicalTrigger('-1 day +25 hours')` does not throw and `getNextRunDate()` returns a future date.

Status: FIXED in commit (`testMixedSignIntervalIsAccepted` added — asserts construction succeeds, description is `every -1 day +25 hours`, and `getNextRunDate()` returns a future date).

## Round 2

### Round 2 status — R1-1 and R1-2

**R1-1: Resolved.** CHANGELOG.md lines 59–65 contain a properly formatted entry under `[Unreleased] > ### Fixed` with `[#667]` issue reference, accurate description matching the code change, and Keep a Changelog compliance verified (correct section, issue link, line wrapping ≤ 74 chars).

**R1-2: Resolved.** `tests/PeriodicalTriggerTest.php:100–113` contains `testMixedSignIntervalIsAccepted` which constructs `PeriodicalTrigger('-1 day +25 hours')` (nets +1h forward), asserts the description string, and asserts `getNextRunDate()` returns a future `DateTimeImmutable`. Empirically verified: `DateInterval::createFromDateString('-1 day +25 hours')` produces `d=-1, h=25, invert=0`, and `add()` moves +1h forward. The test is a regression guard (passes on both master and fix branch by design — it would fail against a field-wise positivity check).

### New findings (round 2)

No new findings.
