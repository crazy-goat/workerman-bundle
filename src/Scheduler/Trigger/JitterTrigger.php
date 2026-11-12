<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Scheduler\Trigger;

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

    public function innerTrigger(): TriggerInterface
    {
        return $this->trigger;
    }

    public function getNextRunDate(\DateTimeImmutable $now): \DateTimeImmutable|null
    {
        $date = $this->trigger->getNextRunDate($now);

        return $date instanceof \DateTimeImmutable ? $this->applyJitter($date) : null;
    }

    public function applyJitter(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $seconds = $this->randomizer->getInt(0, $this->maxSeconds);

        return $date->modify(sprintf('+%d seconds', $seconds));
    }
}
