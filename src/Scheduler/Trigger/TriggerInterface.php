<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Scheduler\Trigger;

/**
 * Determines the next scheduled execution time for a recurring task.
 *
 * Implementations specify when a scheduled task should next run. The
 * SchedulerWorker uses TriggerInterface to schedule delayed callbacks:
 * after a task completes, getNextRunDate() is called to determine when
 * it should fire again.
 *
 * Supported trigger types:
 *  - CronExpressionTrigger: standard cron expressions (via dragonmantank/cron-expression).
 *  - PeriodicalTrigger: fixed intervals (seconds, ISO 8601 durations, relative date strings).
 *  - DateTimeTrigger: single absolute date/time (one-shot).
 *  - JitterTrigger: decorator that adds random jitter to any trigger.
 *
 * Use TriggerFactory::create() to instantiate a trigger from a configuration
 * value (string, int, DateInterval, or DateTimeImmutable).
 *
 * Lifecycle: one trigger instance per scheduled task, evaluated repeatedly
 * during the worker's lifetime. Implementations must be stateless or handle
 * repeated getNextRunDate() calls correctly.
 *
 * @see TriggerFactory::create() For creating triggers from config values.
 * @see CronExpressionTrigger For cron-based scheduling.
 * @see PeriodicalTrigger For periodic interval scheduling.
 * @see DateTimeTrigger For one-shot date-based scheduling.
 * @see JitterTrigger For adding random jitter to an existing trigger.
 */
interface TriggerInterface extends \Stringable
{
    /**
     * Calculate the next scheduled run date/time after the given moment.
     *
     * Given the current time (or any reference moment), returns the next
     * DateTimeImmutable when the task should run, or null if no future run
     * is scheduled (e.g., a one-shot DateTimeTrigger whose date has passed).
     *
     * The SchedulerWorker uses the returned value to set a delayed timer.
     * The timer fires at $nextRunDate, after which getNextRunDate() is called
     * again to schedule the subsequent run.
     *
     * @param \DateTimeImmutable $now The reference date/time (typically the
     *                                current time when scheduling).
     *
     * @return \DateTimeImmutable|null The next run date/time, or null if the
     *                                 trigger has no future occurrences.
     */
    public function getNextRunDate(\DateTimeImmutable $now): \DateTimeImmutable|null;
}
