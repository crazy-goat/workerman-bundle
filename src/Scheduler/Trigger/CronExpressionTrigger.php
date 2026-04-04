<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Scheduler\Trigger;

use CrazyGoat\WorkermanBundle\Exception\InvalidCronExpressionException;
use Cron\CronExpression;

final class CronExpressionTrigger implements TriggerInterface
{
    private CronExpression $expression;

    public function __construct(string $expression)
    {
        if (!class_exists(CronExpression::class)) {
            throw new InvalidCronExpressionException(sprintf('You cannot use "%s" as the "cron expression" package is not installed. Try running "composer require dragonmantank/cron-expression".', self::class));
        }

        try {
            $this->expression = new CronExpression($expression);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidCronExpressionException(sprintf('Invalid cron expression "%s"', $expression), 0, $e);
        }
    }

    public function __toString(): string
    {
        return (string) $this->expression->getExpression();
    }

    public function getNextRunDate(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromMutable($this->expression->getNextRunDate($now));
    }
}
