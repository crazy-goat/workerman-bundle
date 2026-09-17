# Review Round 2 — Issue #700: SchedulerWorker discards trigger exception message

**Branch:** feat/issue-700-schedulerworker-discards-trigger-excepti
**Base:** origin/master
**Files in diff:**
- `src/Worker/SchedulerWorker.php` (1 line changed)
- `tests/Worker/SchedulerWorkerTest.php` (1 assertion added to existing test, 1 new test)

No code changed since round 1. This is a re-verification round.

---

## 1. docs/helpers review (tag index)

Tags matching the diff: `src/Worker` → `scheduler` (FAQ-020, FAQ-021),
`tests` (FAQ-010, FAQ-011, FAQ-022, FAQ-035, FAQ-037), `logging`
(DEC-017), `exceptions` (DEC-020).

**FAQ-020** (JitterTrigger unwrapping) — not applicable: the diff does
not branch on a trigger type; it only appends `$e->getMessage()` to a log
line.

**FAQ-021** (DateInterval fractional-second parser) — tangentially
relevant: the new test uses `'PT'` which fails `new \DateInterval('PT')`.
No sub-second behavior involved; no violation.

**FAQ-037** (New PHP syntax vs minimum supported version) — checked: the
diff uses no version-gated syntax. `catch (\InvalidArgumentException $e)`
with a named variable is basic PHP syntax available since PHP 5.5. No
violation.

**DEC-017** (No-logger warning channels use `error_log()`) — not
applicable: this path uses `$this->worker->log()` (PSR-3-like Workerman
logger), not a no-logger fallback. No `trigger_error` introduced.

**DEC-020** (Exception-hierarchy usage is gated) — not applicable: no
new exception class is added. The diff only captures an existing one.

**DEC-006** (Security hardening) — checked: the change appends
`$e->getMessage()` to a log line. The schedule value comes from
application configuration (PHP attribute or YAML tag), not from HTTP
input. No attacker-controlled data reaches this log path. No security
violation.

No documented decisions are violated by this diff.

## 2. Earlier findings (findings-review.md)

### R1-1 — `src/Worker/SchedulerWorker.php:62` — empty `$e->getMessage()` → trailing `: `

**Status: not a real finding** (confirmed, with evidence verified independently).

R1-1 claimed that if `$e->getMessage()` returns `''`, the log line ends
with a trailing `: ` (cosmetically awkward). Round 1 answered this as
"not a real finding — deliberately not fixed" with evidence that every
exception on this path is `InvalidTriggerException` built via `sprintf`
with a non-empty literal.

**Independent verification of the evidence:** I traced every exception
throw site reachable from `TriggerFactory::create()` and confirmed each
produces a non-empty message:

1. `PeriodicalTrigger.php:32` — `new InvalidTriggerException('Invalid numeric interval')` — non-empty literal ✓
2. `PeriodicalTrigger.php:41` — `new InvalidTriggerException(sprintf('Invalid string interval "%s"', $interval))` — sprintf always produces at least `Invalid string interval ""` ✓
3. `PeriodicalTrigger.php:54` — `new InvalidTriggerException('Interval must be a positive duration')` — non-empty literal ✓
4. `PeriodicalTrigger.php:61` — `new InvalidTriggerException(sprintf('Invalid interval "%s": %s', $original, $e->getMessage()))` — wraps any `\Throwable` caught at line 59; the inner `$e->getMessage()` comes from PHP-native exceptions (`DateMalformedIntervalStringException` / `\Exception` for `new \DateInterval()`, `\Exception` for `createFromDateString()`), all of which produce non-empty messages ✓
5. `DateTimeTrigger.php:24` — `new InvalidTriggerException(sprintf('Invalid date string "%s": %s', $date, $e->getMessage()))` — same pattern, inner exception from `new \DateTimeImmutable()` always non-empty ✓
6. `CronExpressionTrigger.php:19` — `new InvalidCronExpressionException(sprintf('Invalid cron expression "%s"', $expression))` — non-empty ✓

All six throw sites produce non-empty messages. Additionally, the
exception hierarchy is confirmed: `InvalidTriggerException` and
`InvalidCronExpressionException` both extend `SchedulerException` which
extends `\InvalidArgumentException` — so both are caught by the `catch
(\InvalidArgumentException $e)` at line 61. No raw `\InvalidArgumentException`
from vendor code can escape: `CronExpressionTrigger::__construct()`
catches `\InvalidArgumentException` from `new CronExpression()` and wraps
it, and `CronExpression::isValidExpression()` catches
`\InvalidArgumentException` and returns false (so the cron constructor
path is only entered for pre-validated expressions).

**Conclusion: R1-1 is confirmed as "not a real finding."** An empty
`$e->getMessage()` is unreachable on this code path. No code change
needed.

**Automated check that could catch this:** A unit test asserting the log
line does not end with `: ` (trailing colon-space). Not worth adding
since the condition is unreachable.

## 3. New issues in the diff

### 3a. `src/Worker/SchedulerWorker.php:61-62`

The change from `catch (\InvalidArgumentException)` to `catch
(\InvalidArgumentException $e)` and appending `: %s` with
`$e->getMessage()` is the smallest correct fix.

**Type correctness (PHPStan level 8):** Clean — verified with
`php vendor/bin/phpstan analyse src/Worker/SchedulerWorker.php
--level=8`. `$e` is `\InvalidArgumentException`; `getMessage(): string`;
`sprintf('%s', string)` is type-safe.

**`$serviceConfig['schedule']` in sprintf `%s`:** The config value is
`array<string, mixed>` from `WorkermanCompilerPass`. When it's an
integer (e.g. `schedule: 5`), `sprintf('%s', $int)` casts to string
safely. This was already the case before the diff (the same `%s` for
schedule existed on the pre-change line). No regression.

**Log injection / multi-line messages:** `Worker::log()` calls
`trim((string)$msg)` and writes with a single `\n` terminator. If the
exception message contained newlines, the log would have embedded line
breaks. However: (a) the schedule value is operator-supplied
configuration, not attacker-controlled input — the trust level is the
same as the log file itself; (b) all exception messages from
`InvalidTriggerException` and PHP's `DateInterval`/`DateTimeImmutable`
are single-line. No other source in this codebase sanitizes log messages
for newlines. Not a realistic security concern, and adding sanitization
here would be inconsistent and out of scope.

**PSR-12 / php-cs-fixer:** Clean — `php-cs-fixer --dry-run` reports 0
fixable files across the project.

### 3b. `tests/Worker/SchedulerWorkerTest.php`

**Existing test (`testZeroIntervalTaskIsSkippedWithIncorrectTriggerLog`):**
One assertion added: `assertStringContainsString('Interval must be a
positive duration', $output)`. This pins the bundle-owned
`PeriodicalTrigger` message. The full message reaching the catch is
`Invalid interval "PT0S": Interval must be a positive duration`
(double-wrapped by `PeriodicalTrigger`'s `catch(\Throwable)` — a
pre-existing cosmetic issue noted in `findings-coder.md` item 2, out of
scope). The substring assertion succeeds. Correct and meaningful.

**New test (`testIncorrectTriggerLogIncludesExceptionMessage`):** Uses
`'PT'` (malformed ISO-8601 duration) to exercise the `new
\DateInterval('PT')` → `DateMalformedIntervalStringException` →
`PeriodicalTrigger::catch(\Throwable)` → `InvalidTriggerException` path.
Assertions:
1. `assertStringContainsString('Task "my_service" skipped.', $output)` — skip path taken ✓
2. `assertStringContainsString('Trigger "PT" is incorrect:', $output)` — new `:` suffix present ✓
3. `assertStringNotContainsString('Task "my_service" scheduled', $output)` — task not scheduled ✓
4. `assertMatchesRegularExpression('/Trigger "PT" is incorrect: .+/', $output)` — non-empty message after colon ✓

The regex `.+` is the correct choice: the exact `DateInterval` error
text is PHP-version-dependent ("Unknown or bad format (PT)" on PHP 8.3+,
different wording on PHP 8.2). The test is stable across all supported
versions (8.2–8.5). Verified: `php vendor/bin/phpunit --filter
testIncorrectTriggerLogIncludesExceptionMessage` passes.

**Cross-version note (verified):** On PHP < 8.3, `new
\DateInterval('PT')` throws `\Exception` (not
`DateMalformedIntervalStringException`); on PHP >= 8.3, it throws
`DateMalformedIntervalStringException` (which extends `DateException`
extends `\Exception`, NOT `\InvalidArgumentException`). In both cases,
`PeriodicalTrigger::__construct()`'s `catch (\Throwable $e)` at line 59
catches it and re-throws as `InvalidTriggerException` (which IS an
`\InvalidArgumentException`). So the test is valid across all PHP
versions — the PHP-native exception never reaches `SchedulerWorker`'s
catch directly.

### Test coverage assessment

The two tests cover both rejection paths that surface a message:
- `PT0S` → `PeriodicalTrigger` validation (bundle-owned message, exact assertion)
- `PT` → `DateInterval` constructor failure (PHP-native message, regex assertion)

The coder noted a gap (no cron expression rejection test) in
`findings-coder.md` item 3 — pre-existing, not introduced by this diff,
low priority.

## 4. Automated checks run

| Check | Command | Result |
|-------|---------|--------|
| PHPStan level 8 | `php vendor/bin/phpstan analyse src/Worker/SchedulerWorker.php tests/Worker/SchedulerWorkerTest.php --level=8` | ✅ No errors |
| php-cs-fixer dry-run | `php vendor/bin/php-cs-fixer fix --dry-run --config=.php-cs-fixer.dist.php` | ✅ 0 of 259 files fixable |
| PHPUnit SchedulerWorkerTest | `php vendor/bin/phpunit --filter SchedulerWorkerTest --no-coverage` | ✅ 42 tests, 126 assertions, all pass |
| PHPUnit new test only | `php vendor/bin/phpunit --filter testIncorrectTriggerLogIncludesExceptionMessage --no-coverage` | ✅ 1 test, 6 assertions |

## 5. Findings summary

| # | File:line | What | Severity | Status |
|---|-----------|------|----------|--------|
| R1-1 | `src/Worker/SchedulerWorker.php:62` | If `$e->getMessage()` is empty, log line ends with trailing `: ` (cosmetic) | nit | not a real finding (confirmed) — all throw sites produce non-empty messages |

**No new findings in round 2.** The diff is the smallest correct fix
for issue #700, well-tested, type-safe, and consistent with the existing
code style. No `high`, `medium`, `low`, or `nit` findings to report.

## 6. Candidate knowledge-base entries

None proposed. The change is a one-line append to a log message — no
non-obvious pitfall, no reusable lesson. The coder's observation about
double-wrapping in `PeriodicalTrigger::__construct()` (findings-coder.md
item 2) is marginally interesting but not KB-worthy (it's a cosmetic
redundancy in an exception message, not a recurring trap).
