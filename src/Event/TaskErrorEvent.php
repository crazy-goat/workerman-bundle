<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when a scheduled task throws an exception.
 *
 * Listeners receive the thrown exception, the service class name, and
 * the task name. The error is not re-thrown — the child process will
 * exit with code 1 after the event has been dispatched.
 *
 * This is an extension point — use it to implement post-failure logic
 * such as logging, alerting, or metrics.
 *
 * @see TaskStartEvent Fired before the task runs
 * @see \CrazyGoat\WorkermanBundle\Handler\ServiceHandlerTrait The dispatch site in the shared invoke flow
 */
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
