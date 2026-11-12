<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Scheduler\Trigger;

use Cron\CronExpression;

final class TriggerFactory
{
    /**
     * @see https://en.wikipedia.org/wiki/ISO_8601#Durations
     * @see https://www.php.net/manual/en/dateinterval.createfromdatestring.php
     * @see https://github.com/dragonmantank/cron-expression
     * @throws \InvalidArgumentException
     */
    public static function create(string|int|\DateInterval|\DateTimeImmutable $expression, int $jitter = 0): TriggerInterface
    {
        if (is_string($expression) && preg_match('/^\d+$/', $expression) === 1) {
            $expression = (int) $expression;
        }

        if (is_string($expression)) {
            $dateTime = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $expression);
            if ($dateTime instanceof \DateTimeImmutable) {
                $expression = $dateTime;
            }
        }

        $trigger = match (true) {
            $expression instanceof \DateTimeImmutable => new DateTimeTrigger($expression),
            is_string($expression) && class_exists(CronExpression::class) && CronExpression::isValidExpression($expression) => new CronExpressionTrigger($expression),
            default => new PeriodicalTrigger($expression),
        };

        return $jitter > 0 ? new JitterTrigger($trigger, $jitter) : $trigger;
    }
}
