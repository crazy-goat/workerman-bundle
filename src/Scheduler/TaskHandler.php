<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Scheduler;

use CrazyGoat\WorkermanBundle\Event\TaskErrorEvent;
use CrazyGoat\WorkermanBundle\Event\TaskStartEvent;
use CrazyGoat\WorkermanBundle\Handler\ServiceHandlerTrait;
use CrazyGoat\WorkermanBundle\Util\ServiceMethod;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class TaskHandler
{
    use ServiceHandlerTrait;

    public function __construct(
        private ContainerInterface $locator,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(ServiceMethod $serviceMethod, string $taskName): void
    {
        $this->dispatchAndInvoke($serviceMethod, $taskName, TaskStartEvent::class, TaskErrorEvent::class);
    }
}
