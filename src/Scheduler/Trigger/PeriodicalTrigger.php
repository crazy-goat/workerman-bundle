<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Scheduler\Trigger;

use CrazyGoat\WorkermanBundle\Exception\InvalidTriggerException;

/**
 * Fixed-rate periodic trigger.
 *
 * The task fires at multiples of the interval counted from the moment it
 * was first scheduled. The next run is computed from the previous run's
 * scheduled time, not from the current time, so per-run overhead does not
 * shift the cadence: a late rescheduling (slow run, lock contention) skips
 * forward by whole intervals and resumes on the next grid slot instead of
 * firing a burst of catch-up executions. Sub-second intervals (a
 * DateInterval carrying a fraction, e.g. createFromDateString('500 ms'))
 * are preserved.
 */
final class PeriodicalTrigger implements TriggerInterface
{
    private \DateInterval $interval;
    private string $description;

    public function __construct(string|int|\DateInterval $interval)
    {
        try {
            if (is_int($interval)) {
                $dateInterval = \DateInterval::createFromDateString(sprintf('%d seconds', $interval));
                if ($dateInterval === false) {
                    throw new InvalidTriggerException('Invalid numeric interval');
                }
                $this->interval = $dateInterval;
                $this->description = sprintf('every %d', $interval);
            } elseif (\is_string($interval) && str_starts_with($interval, 'P')) {
                $this->interval = new \DateInterval($interval);
                $this->description = sprintf('DateInterval (%s)', $interval);
            } elseif (\is_string($interval)) {
                $dateInterval = \DateInterval::createFromDateString($interval);
                if ($dateInterval === false) {
                    throw new InvalidTriggerException(sprintf('Invalid string interval "%s"', $interval));
                }
                $this->interval = $dateInterval;
                $this->description = sprintf('every %s', $interval);
            } else {
                $this->interval = $interval;
                $this->description = 'DateInterval';
            }
        } catch (\Throwable $e) {
            $original = $interval instanceof \DateInterval ? 'instance of \DateInterval' : (string) $interval;
            throw new InvalidTriggerException(sprintf('Invalid interval "%s": %s', $original, $e->getMessage()), 0, $e);
        }
    }

    public function getNextRunDate(\DateTimeImmutable $now): \DateTimeImmutable|null
    {
        $date = $now->add($this->interval);

        return $date > $now ? $date : null;
    }

    public function __toString(): string
    {
        return $this->description;
    }
}
