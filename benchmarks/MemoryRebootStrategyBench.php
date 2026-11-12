<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Benchmark;

use CrazyGoat\WorkermanBundle\Reboot\Strategy\MemoryRebootStrategy;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[BeforeMethods('init')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class MemoryRebootStrategyBench
{
    private MemoryRebootStrategy $strategyBelowLimit;
    private MemoryRebootStrategy $strategyAboveLimit;
    private MemoryRebootStrategy $strategyWithGc;

    public function init(): void
    {
        $this->strategyBelowLimit = new MemoryRebootStrategy(
            limit: 1024 * 1024 * 1024,
            gcLimit: null,
        );

        $this->strategyAboveLimit = new MemoryRebootStrategy(
            limit: 1,
            gcLimit: null,
        );

        $this->strategyWithGc = new MemoryRebootStrategy(
            limit: 1024 * 1024 * 1024,
            gcLimit: 1,
            gcCooldown: 3600,
        );
    }

    public function benchBelowLimit(): void
    {
        $this->strategyBelowLimit->shouldReboot();
    }

    public function benchAboveLimit(): void
    {
        $this->strategyAboveLimit->shouldReboot();
    }

    public function benchWithGcPath(): void
    {
        $this->strategyWithGc->shouldReboot();
    }
}
