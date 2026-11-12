<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Scheduler\Trigger;

/**
 * @see TriggerFactory::create()
 * @see CronExpressionTrigger
 * @see PeriodicalTrigger
 * @see DateTimeTrigger
 * @see JitterTrigger
 */
interface TriggerInterface extends \Stringable
{
    public function getNextRunDate(\DateTimeImmutable $now): \DateTimeImmutable|null;
}
