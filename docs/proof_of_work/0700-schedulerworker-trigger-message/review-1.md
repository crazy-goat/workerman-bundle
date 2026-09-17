# Review Round 1 — Issue #700: SchedulerWorker discards trigger exception message

**Branch:** feat/issue-700-schedulerworker-discards-trigger-excepti
**Base:** origin/master
**Commit:** 0df509b
**Files in diff:**
- `src/Worker/SchedulerWorker.php` (1 line changed)
- `tests/Worker/SchedulerWorkerTest.php` (1 assertion added, 1 test added)

---

## 1. docs/helpers review (tag index)

Tags matching the diff: `src/Worker` → `scheduler` (FAQ-020, FAQ-021),
`tests` (FAQ-010, FAQ-011, FAQ-022, FAQ-035, etc.), `logging` (DEC-017).

**FAQ-020** (JitterTrigger unwrapping) — not applicable: the diff does not
branch on a trigger type; it only appends `$e->getMessage()` to a log line.

**FAQ-021** (DateInterval fractional-second parser) — tangentially relevant:
the new test uses `'PT'` which exercises `new \DateInterval('PT')`. The test
does not assert sub-second behavior, so no violation.

**DEC-017** (No-logger warning channels use `error_log()`) — not applicable:
this code path uses `$this->worker->log()` (PSR-3-like Workerman logger),
not a no-logger fallback. No `trigger_error` introduced.

**DEC-006** (Security hardening) — checked: the change appends
`$e->getMessage()` to a log line. The schedule value comes from application
configuration (PHP attribute or YAML tag), not from HTTP input. No
attacker-controlled data reaches this log path. No security violation.

No documented decisions are violated by this diff.

## 2. Earlier findings (findings-review.md)

`findings-review.md` does not exist yet — this is round 1. No earlier
findings to reconcile.

## 3. Diff analysis

### Change in `src/Worker/SchedulerWorker.php`

```diff
-                } catch (\InvalidArgumentException) {
-                    $this->worker->log(sprintf('%s Task "%s" skipped. Trigger "%s" is incorrect', $this->worker->name, $taskName, $serviceConfig['schedule']));
+                } catch (\InvalidArgumentException $e) {
+                    $this->worker->log(sprintf('%s Task "%s" skipped. Trigger "%s" is incorrect: %s', $this->worker->name, $taskName, $serviceConfig['schedule'], $e->getMessage()));
```

**Type correctness (PHPStan level 8):** Clean. `$e` is
`\InvalidArgumentException`; `getMessage()` returns `string`. `sprintf`
with `%s` accepts `string`. PHPStan reports no errors on either file.

**`$serviceConfig['schedule']` type in sprintf `%s`:** The config value
reaches `SchedulerWorker` as `array<string, mixed>` (from
`WorkermanCompilerPass::process()` which maps tag attributes). The `AsTask`
attribute declares `?string $schedule`, but tag attributes set in YAML can
also be integers (the existing `testTaskScheduledWithDefaultName` uses
`schedule: 5`). `sprintf('%s', $int)` casts to string in PHP — safe and
consistent with the pre-existing behavior (the same `%s` for schedule was
already on the line before this change). No regression.

**Empty message edge case:** If `$e->getMessage()` returns `''` (empty
string), the log line ends with `is incorrect: ` (trailing colon-space).
This is cosmetically awkward but not a bug — the operator still sees the
trigger value and "is incorrect" status. No `\InvalidArgumentException`
subclass in this codebase produces an empty message:
  - `InvalidTriggerException` always passes a descriptive message.
  - `new \DateInterval('PT')` always produces a non-empty error.
This is a theoretical-only edge case. **Severity: nit.**

**Multi-line message / log injection:** `Worker::log()` calls
`trim((string)$msg)` and writes with a single `\n` terminator. If the
exception message contained newlines, the log would have embedded line
breaks. However: (a) the schedule value is operator-supplied configuration,
not attacker-controlled input — the trust level is the same as the log file
itself; (b) the exception messages from `InvalidTriggerException` and PHP's
`DateInterval` are single-line. This is not a realistic security concern.
No other source in this codebase sanitizes log messages for newlines, so
adding such sanitization here would be inconsistent and out of scope.

**PSR-12 / php-cs-fixer:** Clean — `php-cs-fixer --dry-run` reports 0
fixable files. The line is long (~120 chars) but the pre-existing line was
already long and the project does not enforce a strict line-length gate.

### Change in `tests/Worker/SchedulerWorkerTest.php`

**Existing test (`testZeroIntervalTaskIsSkippedWithIncorrectTriggerLog`):**
One assertion added: `assertStringContainsString('Interval must be a
positive duration', $output)`. This is an exact-string assertion against a
message owned by `PeriodicalTrigger` (our own code, stable across PHP
versions). Meaningful and correctly pins the new behavior.

**New test (`testIncorrectTriggerLogIncludesExceptionMessage`):** Uses
`'PT'` (malformed ISO-8601 duration) to exercise a different rejection path
(`new \DateInterval('PT')` throws). Assertions:
1. `assertStringContainsString('Task "my_service" skipped.', $output)` —
   confirms the skip path was taken.
2. `assertStringContainsString('Trigger "PT" is incorrect:', $output)` —
   confirms the new `:` suffix is present (would have failed before the fix).
3. `assertStringNotContainsString('Task "my_service" scheduled', $output)`
   — confirms the task was not scheduled.
4. `assertMatchesRegularExpression('/Trigger "PT" is incorrect: .+/', ...)`
   — confirms a non-empty message follows the colon.

The regex `.+` is the correct choice here: the exact `DateInterval` error
text is PHP-version-dependent ("Unknown or bad format (PT)" on some
versions). The coder's decision to use a regex for the PHP-native message
and an exact string for the bundle-owned message is well-reasoned and
documented in `code-decision-1.md`.

**Test coverage assessment:** The two tests together cover both rejection
paths that surface a message:
  - `PT0S` → `PeriodicalTrigger` validation (bundle-owned message).
  - `PT` → `DateInterval` constructor (PHP-native message).
The catch is generic (`\InvalidArgumentException`), so any future trigger
type's rejection would also be caught. The coder noted a gap (no cron
expression rejection test) in `findings-coder.md` item 3 — this is a
pre-existing coverage gap, not introduced by this diff, and low priority.

### Automated checks run

| Check | Result |
|-------|--------|
| PHPStan level 8 (`src/Worker/SchedulerWorker.php`, `tests/Worker/SchedulerWorkerTest.php`) | ✅ No errors |
| php-cs-fixer dry-run | ✅ 0 fixable |
| PHPUnit `--filter SchedulerWorkerTest` | ✅ 42 tests, 126 assertions, all pass |

### Checks that could have caught findings

- PHPStan: would catch a type mismatch in the sprintf args (none here).
- php-cs-fixer: would catch style violations (none here).
- The empty-message edge case (nit) could be caught by a test asserting
  the log line does not end with a trailing `: `, but this is not worth
  adding since no real exception produces an empty message.

## 4. Findings summary

| # | File:line | What | Severity | Status |
|---|-----------|------|----------|--------|
| R1-1 | `src/Worker/SchedulerWorker.php:62` | If `$e->getMessage()` is empty, log line ends with trailing `: ` (cosmetic) | nit | open |

No `high`, `medium`, or `low` findings. The diff is the smallest correct
fix for issue #700, well-tested, and consistent with the existing code
style. The coder's `findings-coder.md` items are all correctly scoped as
out-of-range.

## 5. Candidate knowledge-base entries

None proposed. The change is a one-line append to a log message — no
non-obvious pitfall, no reusable lesson. The coder's observation about
double-wrapping in `PeriodicalTrigger::__construct()` (findings-coder.md
item 2) is marginally interesting but not KB-worthy (it's a cosmetic
redundancy in an exception message, not a recurring trap).
