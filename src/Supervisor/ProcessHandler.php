<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Supervisor;

use CrazyGoat\WorkermanBundle\Event\ProcessErrorEvent;
use CrazyGoat\WorkermanBundle\Event\ProcessStartEvent;
use CrazyGoat\WorkermanBundle\Util\ServiceMethodHelper;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class ProcessHandler
{
    public function __construct(
        private ContainerInterface $locator,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(string $service, string $processName): void
    {
        [$serviceName, $method] = ServiceMethodHelper::split($service);
        $service = $this->locator->get($serviceName);
        assert(is_object($service));

        $this->eventDispatcher->dispatch(new ProcessStartEvent($service::class, $processName));

        try {
            if (!method_exists($service, $method)) {
                throw new \InvalidArgumentException(
                    sprintf('Method "%s" does not exist on service "%s" (class "%s").', $method, $serviceName, $service::class),
                );
            }
            $service->$method();
        } catch (\Throwable $e) {
            $this->eventDispatcher->dispatch(new ProcessErrorEvent($e, $service::class, $processName));
        }
    }
}
