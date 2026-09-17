# Findings — Review — Issue #700

Round 1 findings. No earlier rounds exist.

| # | File:line | What is wrong | Severity | What happened to it |
|---|-----------|---------------|----------|---------------------|
| R1-1 | `src/Worker/SchedulerWorker.php:62` | If `$e->getMessage()` returns `''`, the log line ends with a trailing `: ` (cosmetically awkward). No real `\InvalidArgumentException` in this codebase produces an empty message, so this is theoretical only. | nit | not a real finding — deliberately not fixed: every exception on this path is `InvalidTriggerException` built via `sprintf` with a non-empty literal (`PeriodicalTrigger.php:32,41,54,61`, `CronExpressionTrigger.php:19`, `DateTimeTrigger.php:24`), so an empty message is unreachable; no code change made |

---

Round 2: no code changed since round 1. Re-verified R1-1 evidence independently; no new findings.

| # | File:line | What is wrong | Severity | What happened to it |
|---|-----------|---------------|----------|---------------------|
| R1-1 | `src/Worker/SchedulerWorker.php:62` | If `$e->getMessage()` returns `''`, the log line ends with a trailing `: ` (cosmetically awkward). | nit | not a real finding — confirmed in round 2: independently traced all 6 throw sites reachable from `TriggerFactory::create()` (`PeriodicalTrigger.php:32,41,54,61`, `DateTimeTrigger.php:24`, `CronExpressionTrigger.php:19`); all produce non-empty messages via `sprintf` with non-empty literals; no raw vendor `\InvalidArgumentException` can escape (CronExpressionTrigger catches and wraps; `isValidExpression` catches and returns false); empty message is unreachable |
