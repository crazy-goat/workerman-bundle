<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\Strategy;

final readonly class MemoryRebootStrategy implements RebootStrategyInterface
{
    public function __construct(private int $limit, private ?int $gcLimit)
    {
    }

    public function shouldReboot(): bool
    {
        $memoryUsage = memory_get_usage();

        if ($this->gcLimit !== null && $memoryUsage > $this->gcLimit) {
            gc_collect_cycles();
            $memoryUsage = memory_get_usage();
        }
        return $memoryUsage > $this->limit;
    }
}
