<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Scheduler;

use CrazyGoat\WorkermanBundle\Event\TaskErrorEvent;
use CrazyGoat\WorkermanBundle\Event\TaskStartEvent;
use CrazyGoat\WorkermanBundle\Util\ServiceMethod;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class TaskHandler
{
    public function __construct(
        private ContainerInterface $locator,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(ServiceMethod $serviceMethod, string $taskName): void
    {
        $serviceInstance = $this->locator->get($serviceMethod->serviceId);
        assert(is_object($serviceInstance));

        $this->eventDispatcher->dispatch(new TaskStartEvent($serviceInstance::class, $taskName));

        try {
            if (!method_exists($serviceInstance, $serviceMethod->method)) {
                throw new \InvalidArgumentException(
                    sprintf('Method "%s" does not exist on service "%s" (class "%s").', $serviceMethod->method, $serviceMethod->serviceId, $serviceInstance::class),
                );
            }
            $serviceInstance->{$serviceMethod->method}();
        } catch (\Throwable $e) {
            $this->eventDispatcher->dispatch(new TaskErrorEvent($e, $serviceInstance::class, $taskName));
        }
    }
}
