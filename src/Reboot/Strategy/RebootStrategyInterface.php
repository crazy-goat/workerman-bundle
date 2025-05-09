<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\Strategy;

interface RebootStrategyInterface
{
    public function shouldReboot(): bool;
}
