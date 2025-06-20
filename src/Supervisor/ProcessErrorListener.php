<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Supervisor;

use CrazyGoat\WorkermanBundle\Event\ProcessErrorEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ProcessErrorListener implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
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
        $this->logger->critical('Error thrown while executing process "{process}". Message: "{message}"', [
            'exception' => $event->getError(),
            'process' => $event->getProcessName(),
            'message' => $event->getError()->getMessage(),
        ]);
    }
}
