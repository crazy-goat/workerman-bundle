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
