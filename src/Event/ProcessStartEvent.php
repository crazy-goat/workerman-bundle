<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched right before a supervised process is started.
 *
 * Listeners receive the fully-qualified service class name and the
 * configured process name. The event is fired after the service has been
 * resolved from the container but before the configured method is
 * invoked.
 *
 * This is an extension point — use it to implement pre-process logic
 * such as logging, metrics, or dynamic configuration changes.
 *
 * @see ProcessErrorEvent Fired when the process throws
 * @see \CrazyGoat\WorkermanBundle\Handler\ServiceHandlerTrait The dispatch site in the shared invoke flow
 */
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
