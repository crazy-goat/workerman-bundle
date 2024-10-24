<?php

declare(strict_types=1);

namespace Luzrain\WorkermanBundle\Reboot\Strategy;

class MemoryRebootStrategy implements RebootStrategyInterface
{
    public function __construct(private readonly int $limit, private readonly ?int $gcLimit)
    {
    }

    public function shouldReboot(): bool
    {
        if ($this->gcLimit !== null &&  memory_get_usage() > $this->gcLimit) {
            gc_collect_cycles();
        }

        if (memory_get_usage() > $this->limit) {
            return true;
        }

        return false;
    }
}
