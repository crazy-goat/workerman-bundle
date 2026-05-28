<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Handler;

use CrazyGoat\WorkermanBundle\Util\ServiceMethod;

/**
 * Shared __invoke body for TaskHandler and ProcessHandler.
 *
 * Both classes have identical dispatch+invoke logic that differs only
 * in the dispatched event classes. This trait owns that common flow.
 *
 * @internal
 */
trait ServiceHandlerTrait
{
    /**
     * @param class-string $startEventClass FQCN of the start event (e.g. TaskStartEvent::class)
     * @param class-string $errorEventClass FQCN of the error event (e.g. TaskErrorEvent::class)
     */
    private function dispatchAndInvoke(
        ServiceMethod $serviceMethod,
        string $name,
        string $startEventClass,
        string $errorEventClass,
    ): void {
        $serviceInstance = $this->locator->get($serviceMethod->serviceId);
        \assert(\is_object($serviceInstance));

        $this->eventDispatcher->dispatch(new $startEventClass($serviceInstance::class, $name));

        try {
            if (!\method_exists($serviceInstance, $serviceMethod->method)) {
                throw new \InvalidArgumentException(
                    \sprintf('Method "%s" does not exist on service "%s" (class "%s").', $serviceMethod->method, $serviceMethod->serviceId, $serviceInstance::class),
                );
            }
            $serviceInstance->{$serviceMethod->method}();
        } catch (\Throwable $e) {
            $this->eventDispatcher->dispatch(new $errorEventClass($e, $serviceInstance::class, $name));
        }
    }
}
