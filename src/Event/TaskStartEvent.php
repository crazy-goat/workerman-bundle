<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched right before a scheduled task is executed.
 *
 * Listeners receive the fully-qualified service class name and the
 * configured task name. The event is fired after the service has been
 * resolved from the container but before the configured method is
 * invoked.
 *
 * This is an extension point — use it to implement pre-task logic
 * such as logging, metrics, or dynamic configuration changes.
 *
 * @see TaskErrorEvent Fired when the task throws
 * @see \CrazyGoat\WorkermanBundle\Handler\ServiceHandlerTrait The dispatch site in the shared invoke flow
 */
final class TaskStartEvent extends Event
{
    public function __construct(private readonly string $serviceClass, private readonly string $taskName)
    {
    }

    public function getTaskName(): string
    {
        return $this->taskName;
    }

    public function getServiceClass(): string
    {
        return $this->serviceClass;
    }
}
