<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Supervisor;

use CrazyGoat\WorkermanBundle\Event\ProcessErrorEvent;
use CrazyGoat\WorkermanBundle\Event\ProcessStartEvent;
use CrazyGoat\WorkermanBundle\Util\ServiceMethod;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class ProcessHandler
{
    public function __construct(
        private ContainerInterface $locator,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(ServiceMethod $serviceMethod, string $processName): void
    {
        $serviceInstance = $this->locator->get($serviceMethod->serviceId);
        assert(is_object($serviceInstance));

        $this->eventDispatcher->dispatch(new ProcessStartEvent($serviceInstance::class, $processName));

        try {
            if (!method_exists($serviceInstance, $serviceMethod->method)) {
                throw new \InvalidArgumentException(
                    sprintf('Method "%s" does not exist on service "%s" (class "%s").', $serviceMethod->method, $serviceMethod->serviceId, $serviceInstance::class),
                );
            }
            $serviceInstance->{$serviceMethod->method}();
        } catch (\Throwable $e) {
            $this->eventDispatcher->dispatch(new ProcessErrorEvent($e, $serviceInstance::class, $processName));
        }
    }
}
