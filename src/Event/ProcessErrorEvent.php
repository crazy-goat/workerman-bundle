<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class ProcessErrorEvent extends Event
{
    public function __construct(private \Throwable $error, private readonly string $serviceClass, private readonly string $processName)
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

    public function getError(): \Throwable
    {
        return $this->error;
    }

    public function setError(\Throwable $error): void
    {
        $this->error = $error;
    }
}
