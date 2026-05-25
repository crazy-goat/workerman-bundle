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

    public function __invoke(ServiceMethod $service, string $processName): void
    {
        $serviceInstance = $this->locator->get($service->serviceId);
        assert(is_object($serviceInstance));

        $this->eventDispatcher->dispatch(new ProcessStartEvent($serviceInstance::class, $processName));

        try {
            if (!method_exists($serviceInstance, $service->method)) {
                throw new \InvalidArgumentException(
                    sprintf('Method "%s" does not exist on service "%s" (class "%s").', $service->method, $service->serviceId, $serviceInstance::class),
                );
            }
            $serviceInstance->{$service->method}();
        } catch (\Throwable $e) {
            $this->eventDispatcher->dispatch(new ProcessErrorEvent($e, $serviceInstance::class, $processName));
        }
    }
}
