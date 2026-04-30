<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Scheduler;

use CrazyGoat\WorkermanBundle\Event\TaskErrorEvent;
use CrazyGoat\WorkermanBundle\Event\TaskStartEvent;
use CrazyGoat\WorkermanBundle\Util\ServiceMethodHelper;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class TaskHandler
{
    public function __construct(
        private ContainerInterface $locator,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(string $service, string $taskName): void
    {
        [$serviceName, $method] = ServiceMethodHelper::split($service);
        $service = $this->locator->get($serviceName);
        assert(is_object($service));

        $this->eventDispatcher->dispatch(new TaskStartEvent($service::class, $taskName));

        try {
            if (!method_exists($service, $method)) {
                throw new \InvalidArgumentException(
                    sprintf('Method "%s" does not exist on service "%s" (class "%s").', $method, $serviceName, $service::class),
                );
            }
            $service->$method();
        } catch (\Throwable $e) {
            $this->eventDispatcher->dispatch(new TaskErrorEvent($e, $service::class, $taskName));
        }
    }
}
