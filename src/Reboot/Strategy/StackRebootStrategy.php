<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\Strategy;

final class StackRebootStrategy implements RebootStrategyInterface
{
    /**
     * @param iterable<RebootStrategyInterface> $strategies
     */
    public function __construct(private readonly iterable $strategies)
    {
    }

    public function shouldReboot(): bool
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->shouldReboot()) {
                return true;
            }
        }

        return false;
    }
}
