<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Benchmark;

use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;

final class BenchRebootStrategy implements RebootStrategyInterface
{
    public function shouldReboot(): bool
    {
        return false;
    }
}
