<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\Strategy;

use Workerman\Timer;

final class MemoryRebootStrategy implements RebootStrategyInterface
{
    private ?float $lastGcTime = null;

    /**
     * @param int $limit memory usage threshold (bytes) after which the worker
     *                   is reloaded, measured with memory_get_usage() (emalloc
     *                   accounting, not real usage)
     * @param int|null $gcLimit memory usage threshold (bytes) above which a
     *                          garbage collection is attempted before the
     *                          reload decision
     * @param int $gcCooldown minimum seconds between garbage collection attempts
     * @param (\Closure(): void)|null $gcScheduler test seam replacing the default
     *                        collection mechanism; invoked synchronously
     *                        whenever a collection is due
     */
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
            $memoryUsage = $this->collectGarbageIfNeeded($memoryUsage);
        }

        return $memoryUsage > $this->limit;
    }

    /**
     * @return int the memory reading the reload verdict should be based on
     */
    private function collectGarbageIfNeeded(int $memoryUsage): int
    {
        $now = microtime(true);
        if ($this->lastGcTime !== null && ($now - $this->lastGcTime) <= $this->gcCooldown) {
            return $memoryUsage;
        }

        $this->lastGcTime = $now;

        if ($memoryUsage > $this->limit) {
            // The worker is about to be reloaded. Collect synchronously so the
            // verdict can use the post-collection reading: if the collection
            // frees enough memory, the reload is avoided.
            $this->runCollection();

            return memory_get_usage();
        }

        // Preventive collection for upcoming requests: defer it so the request
        // path stays short.
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
                // Not running under Workerman (e.g. unit tests): nothing to defer to.
            }

            return;
        }

        ($this->gcScheduler)();
    }
}
