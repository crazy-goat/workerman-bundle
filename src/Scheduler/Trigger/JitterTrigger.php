<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Scheduler\Trigger;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final readonly class JitterTrigger implements TriggerInterface
{
    public function __construct(
        private TriggerInterface $trigger,
        private int $maxSeconds,
        private \Random\Randomizer $randomizer = new \Random\Randomizer(),
    ) {
    }

    public function __toString(): string
    {
        return sprintf('%s with 0-%d second jitter', $this->trigger, $this->maxSeconds);
    }

    /**
     * The decorated trigger, exposed so schedulers can detect the
     * underlying schedule type (e.g. to apply fixed-rate rebasing to a
     * periodical schedule).
     */
    public function innerTrigger(): TriggerInterface
    {
        return $this->trigger;
    }

    public function getNextRunDate(\DateTimeImmutable $now): \DateTimeImmutable|null
    {
        $date = $this->trigger->getNextRunDate($now);

        return $date instanceof \DateTimeImmutable ? $this->applyJitter($date) : null;
    }

    /**
     * Add a random delay of 0 to the maximum jitter to a planned run time.
     */
    public function applyJitter(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $seconds = $this->randomizer->getInt(0, $this->maxSeconds);

        return $date->modify(sprintf('+%d seconds', $seconds));
    }
}
