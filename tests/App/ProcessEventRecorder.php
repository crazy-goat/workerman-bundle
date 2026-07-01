<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

use CrazyGoat\WorkermanBundle\Event\ProcessErrorEvent;
use CrazyGoat\WorkermanBundle\Event\ProcessStartEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ProcessStartEvent::class, method: 'onStart', priority: -128)]
#[AsEventListener(event: ProcessErrorEvent::class, method: 'onError', priority: -128)]
final readonly class ProcessEventRecorder
{
    public function __construct(
        #[Autowire(value: '%kernel.project_dir%/var/process_start.marker')]
        private string $startMarker,
        #[Autowire(value: '%kernel.project_dir%/var/process_error.marker')]
        private string $errorMarker,
    ) {
    }

    public function onStart(ProcessStartEvent $event): void
    {
        file_put_contents(
            $this->startMarker,
            time() . "\x1f" . $event->getProcessName() . "\n",
            FILE_APPEND,
        );
    }

    public function onError(ProcessErrorEvent $event): void
    {
        file_put_contents(
            $this->errorMarker,
            time() . "\x1f" . $event->getProcessName() . "\x1f" . $event->getError()->getMessage() . "\n",
            FILE_APPEND,
        );
    }
}
