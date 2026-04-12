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
        if ($this->gcLimit !== null &&  memory_get_usage() > $this->gcLimit) {
            gc_collect_cycles();
        }
        return memory_get_usage() > $this->limit;
    }
}
