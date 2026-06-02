<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\Strategy;

use Workerman\Timer;

final class MemoryRebootStrategy implements RebootStrategyInterface
{
    private ?float $lastGcTime = null;

    public function __construct(
        private readonly int $limit,
        private readonly ?int $gcLimit,
        private readonly int $gcCooldown = 60,
        private readonly ?\Closure $gcScheduler = null,
    ) {
    }

    public function needsPeakMemory(): bool
    {
        return false;
    }

    public function shouldReboot(): bool
    {
        $memoryUsage = memory_get_usage();

        if ($this->gcLimit !== null && $memoryUsage > $this->gcLimit) {
            $this->scheduleGarbageCollectionIfNeeded();
        }

        return $memoryUsage > $this->limit;
    }

    private function scheduleGarbageCollectionIfNeeded(): void
    {
        $now = microtime(true);
        if ($this->lastGcTime !== null && ($now - $this->lastGcTime) <= $this->gcCooldown) {
            return;
        }

        $this->lastGcTime = $now;

        $scheduler = $this->gcScheduler ?? static function (): void {
            try {
                Timer::add(0, static function (): void {
                    gc_collect_cycles();
                }, persistent: false);
            } catch (\RuntimeException) {
            }
        };
        $scheduler();
    }
}
