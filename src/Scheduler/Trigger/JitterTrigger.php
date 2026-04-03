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

    public function getNextRunDate(\DateTimeImmutable $now): \DateTimeImmutable|null
    {
        $seconds = $this->randomizer->getInt(0, $this->maxSeconds);
        return $this->trigger->getNextRunDate($now)?->modify(sprintf('+%d seconds', $seconds));
    }
}
