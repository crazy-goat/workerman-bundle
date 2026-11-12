<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\Strategy;

use Workerman\Timer;

final class MemoryRebootStrategy implements RebootStrategyInterface
{
    private ?float $lastGcTime = null;

    /**
     * @param (\Closure(): void)|null $gcScheduler
     */
    public function __construct(
        private readonly int $limit,
        private readonly ?int $gcLimit,
        private readonly int $gcCooldown = 60,
        private readonly ?\Closure $gcScheduler = null,
    ) {
    }

    public function shouldReboot(): bool
    {
        $memoryUsage = memory_get_usage();

        if ($this->gcLimit !== null && $memoryUsage > $this->gcLimit) {
            $memoryUsage = $this->collectGarbageIfNeeded($memoryUsage);
        }

        return $memoryUsage > $this->limit;
    }

    private function collectGarbageIfNeeded(int $memoryUsage): int
    {
        $now = microtime(true);
        if ($this->lastGcTime !== null && ($now - $this->lastGcTime) <= $this->gcCooldown) {
            return $memoryUsage;
        }

        $this->lastGcTime = $now;

        if ($memoryUsage > $this->limit) {
            $this->runCollection();

            return memory_get_usage();
        }

        $this->scheduleCollection();

        return $memoryUsage;
    }

    private function runCollection(): void
    {
        if (!$this->gcScheduler instanceof \Closure) {
            gc_collect_cycles();

            return;
        }

        ($this->gcScheduler)();
    }

    private function scheduleCollection(): void
    {
        if (!$this->gcScheduler instanceof \Closure) {
            try {
                Timer::add(0, static function (): void {
                    gc_collect_cycles();
                }, persistent: false);
            } catch (\RuntimeException) {
            }

            return;
        }

        ($this->gcScheduler)();
    }
}
