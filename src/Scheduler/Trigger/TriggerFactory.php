<?php

declare(strict_types=1);

namespace Luzrain\WorkermanBundle\Scheduler\Trigger;

final class TriggerFactory
{
    /**
     * Sets the trigger frequency.
     *
     * Supported frequency formats:
     *
     *  * An integer to define the frequency as a number of seconds;
     *  * An ISO8601 datetime format;
     *  * An ISO8601 duration format;
     *  * A relative date format as supported by \DateInterval;
     *  * A \DateInterval instance;
     *  * A \DateTimeImmutable instance;
     *  * A cron expression.
     *
     * @see https://en.wikipedia.org/wiki/ISO_8601#Durations
     * @see https://www.php.net/manual/en/dateinterval.createfromdatestring.php
     * @see https://github.com/dragonmantank/cron-expression
     *
     * @throws \InvalidArgumentException
     */
    public static function create(string|int|\DateInterval|\DateTimeImmutable $expression, int $jitter = 0): TriggerInterface
    {
        if (is_string($expression)) {
            $dateTime = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $expression);
            if ($dateTime) {
                $expression = $dateTime;
            }
        }

        $trigger = match (true) {
            $expression instanceof \DateTimeImmutable => new DateTimeTrigger($expression),
            is_string($expression) && count(explode(' ', $expression)) === 5 && str_contains($expression, '*'),
            is_string($expression) && str_starts_with($expression, '@') => new CronExpressionTrigger($expression),
            default => new PeriodicalTrigger($expression),
        };

        return $jitter > 0 ? new JitterTrigger($trigger, $jitter) : $trigger;
    }
}
