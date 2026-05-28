<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Supervisor;

use CrazyGoat\WorkermanBundle\Event\ProcessErrorEvent;
use CrazyGoat\WorkermanBundle\Handler\ServiceErrorListenerTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ProcessErrorListener implements EventSubscriberInterface
{
    use ServiceErrorListenerTrait;

    public function __construct(private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProcessErrorEvent::class => ['onException', -128],
        ];
    }

    public function onException(ProcessErrorEvent $event): void
    {
        $this->logServiceError(
            'Error thrown while executing process "{process}". Message: "{message}"',
            'process',
            $event->getProcessName(),
            $event->getError()->getMessage(),
            $event->getError(),
        );
    }
}
