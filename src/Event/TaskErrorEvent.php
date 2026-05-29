<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class TaskErrorEvent extends Event
{
    public function __construct(
        private readonly \Throwable $error,
        private readonly string $serviceClass,
        private readonly string $taskName,
    ) {
    }

    public function getTaskName(): string
    {
        return $this->taskName;
    }

    public function getServiceClass(): string
    {
        return $this->serviceClass;
    }

    public function getError(): \Throwable
    {
        return $this->error;
    }
}
