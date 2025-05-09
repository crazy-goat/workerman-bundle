<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class ProcessStartEvent extends Event
{
    public function __construct(private readonly string $serviceClass, private readonly string $processName)
    {
    }

    public function getProcessName(): string
    {
        return $this->processName;
    }

    public function getServiceClass(): string
    {
        return $this->serviceClass;
    }
}
