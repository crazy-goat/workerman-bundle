<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\Strategy;

interface RebootStrategyInterface
{
    public function shouldReboot(): bool;

    /**
     * Whether this strategy needs memory_get_peak_usage() to be reset
     * on every request. When no strategy needs it, the HttpRequestHandler
     * skips the memory_reset_peak_usage() call entirely, saving a syscall
     * on the hot path.
     */
    public function needsPeakMemory(): bool;
}
