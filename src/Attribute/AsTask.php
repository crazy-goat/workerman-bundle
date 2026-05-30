<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Attribute;

/**
 * Marks a service class as a scheduled task for the Workerman scheduler.
 *
 * Apply this attribute to any service class registered in the container to
 * have it executed on a recurring schedule. The scheduler (see
 * SchedulerWorker) resolves tagged services and invokes them according to
 * the schedule expression.
 *
 * This attribute is consumed by ServicesConfigurator::configureAutoConfiguration()
 * which registers the `workerman.task` tag for each annotated class. The tag
 * is then picked up by the scheduler at runtime.
 *
 * @see ServicesConfigurator::configureAutoConfiguration()
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsTask
{
    /**
     * @param string|null $name     A human-readable name for the task. If null,
     *                              the service ID is used as the display name.
     * @param string|null $schedule A cron-expression or human-readable interval
     *                              (e.g. `'0 * * * *'`, `'@daily'`, `'1 second'`).
     *                              If null the task is skipped with a warning.
     * @param string|null $method   The method to call on the service.
     *                              Defaults to `__invoke` when null.
     * @param int|null    $jitter   Random delay in seconds added to the scheduled
     *                              time to spread out task execution. 0 means no
     *                              jitter. Null defaults to 0.
     */
    public function __construct(
        public ?string $name = null,
        public ?string $schedule = null,
        public ?string $method = null,
        public ?int $jitter = null,
    ) {
    }
}
