<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\Strategy;

/**
 * @see MemoryRebootStrategy
 * @see MaxJobsRebootStrategy
 * @see ExceptionRebootStrategy
 * @see AlwaysRebootStrategy
 * @see StackRebootStrategy
 */
interface RebootStrategyInterface
{
    public function shouldReboot(): bool;
}
