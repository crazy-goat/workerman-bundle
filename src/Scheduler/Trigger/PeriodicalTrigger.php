<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Scheduler\Trigger;

final class PeriodicalTrigger implements TriggerInterface
{
    private \DateInterval $interval;
    private string $description;

    public function __construct(string|int|\DateInterval $interval)
    {
        try {
            if (is_int($interval)) {
                $dateTime = \DateInterval::createFromDateString(sprintf('%d seconds', $interval));
                assert($dateTime instanceof \DateInterval);
                $this->interval = $dateTime;
                $this->description = sprintf('every %s', $interval);
            } elseif (\is_string($interval) && str_starts_with($interval, 'P')) {
                $this->interval = new \DateInterval($interval);
                $this->description = sprintf('DateInterval (%s)', $interval);
            } elseif (\is_string($interval)) {
                $dateTime = \DateInterval::createFromDateString($interval);
                assert($dateTime instanceof \DateInterval);
                $this->interval = $dateTime;
                $this->description = sprintf('every %s', $interval);
            } else {
                $this->interval = $interval;
                $a = (array) $interval;
                $this->description = isset($a['from_string']) ? sprintf('every %s', $a['date_string']) : 'DateInterval';
            }
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(sprintf('Invalid interval "%s": %s', $interval instanceof \DateInterval ? 'instance of \DateInterval' : $interval, $e->getMessage()), 0, $e);
        }
    }

    public function getNextRunDate(\DateTimeImmutable $now): \DateTimeImmutable|null
    {
        $period = new \DatePeriod($now, $this->interval, $now->modify('+10 year'));
        /** @var \Iterator<\DateTimeImmutable> $iterator */
        $iterator = $period->getIterator();
        $iterator->next();
        $date = $iterator->current();

        return $date > $now ? $date : null;
    }

    public function __toString(): string
    {
        return $this->description;
    }
}
