<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Benchmark;

use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;

/**
 * No-op reboot strategy for benchmarking.
 */
final class BenchRebootStrategy implements RebootStrategyInterface
{
    public function shouldReboot(): bool
    {
        return false;
    }

    public function needsPeakMemory(): bool
    {
        return false;
    }
}
