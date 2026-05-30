<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when a supervised process throws an exception.
 *
 * Listeners receive the thrown exception, the service class name, and
 * the process name. The error is not re-thrown — the child process will
 * exit with code 1 after the event has been dispatched.
 *
 * This is an extension point — use it to implement post-failure logic
 * such as logging, alerting, or metrics.
 *
 * @see ProcessStartEvent Fired before the process runs
 * @see \CrazyGoat\WorkermanBundle\Handler\ServiceHandlerTrait The dispatch site in the shared invoke flow
 */
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
