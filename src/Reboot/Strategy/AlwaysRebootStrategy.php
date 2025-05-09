<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\Strategy;

final class AlwaysRebootStrategy implements RebootStrategyInterface
{
    public function shouldReboot(): bool
    {
        return true;
    }
}
