<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Benchmark;

use CrazyGoat\WorkermanBundle\Reboot\Strategy\MemoryRebootStrategy;
use PhpBench\Benchmark\Metadata\Annotations\BeforeMethods;
use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use PhpBench\Benchmark\Metadata\Annotations\Warmup;

/**
 * Benchmark MemoryRebootStrategy::shouldReboot — the per-request check that
 * decides whether a worker should be gracefully restarted based on memory
 * consumption.
 *
 * @BeforeMethods("init")
 * @Revs(1000)
 * @Iterations(5)
 * @Warmup(1)
 */
final class MemoryRebootStrategyBench
{
    private MemoryRebootStrategy $strategyBelowLimit;
    private MemoryRebootStrategy $strategyAboveLimit;
    private MemoryRebootStrategy $strategyWithGc;

    public function init(): void
    {
        // Current memory usage is typically well below 1 GiB in a benchmark
        $this->strategyBelowLimit = new MemoryRebootStrategy(
            limit: 1024 * 1024 * 1024, // 1 GiB
            gcLimit: null,
        );

        // Set limit to 1 byte so every call triggers the > check
        $this->strategyAboveLimit = new MemoryRebootStrategy(
            limit: 1,
            gcLimit: null,
        );

        // Strategy with GC path — gcLimit below typical usage, cooldown long enough
        // that GC is only scheduled once during warmup
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
