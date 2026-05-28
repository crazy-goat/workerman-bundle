<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Supervisor;

use CrazyGoat\WorkermanBundle\Event\ProcessErrorEvent;
use CrazyGoat\WorkermanBundle\Event\ProcessStartEvent;
use CrazyGoat\WorkermanBundle\Handler\ServiceHandlerTrait;
use CrazyGoat\WorkermanBundle\Util\ServiceMethod;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class ProcessHandler
{
    use ServiceHandlerTrait;

    public function __construct(
        private ContainerInterface $locator,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(ServiceMethod $serviceMethod, string $processName): void
    {
        $this->dispatchAndInvoke($serviceMethod, $processName, ProcessStartEvent::class, ProcessErrorEvent::class);
    }
}
