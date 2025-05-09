<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Supervisor;

use CrazyGoat\WorkermanBundle\Event\ProcessErrorEvent;
use CrazyGoat\WorkermanBundle\Event\ProcessStartEvent;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ProcessHandler
{
    public function __construct(
        private readonly ContainerInterface $locator,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(string $service, string $processName): void
    {
        [$serviceName, $method] = explode('::', $service, 2);
        $service = $this->locator->get($serviceName);
        assert(is_object($service));

        $this->eventDispatcher->dispatch(new ProcessStartEvent($service::class, $processName));

        try {
            $service->$method();
        } catch (\Throwable $e) {
            $this->eventDispatcher->dispatch(new ProcessErrorEvent($e, $service::class, $processName));
        }
    }
}
