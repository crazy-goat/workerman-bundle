<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Scheduler;

use CrazyGoat\WorkermanBundle\Event\TaskErrorEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class TaskErrorListener implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TaskErrorEvent::class => ['onException', -128],
        ];
    }

    public function onException(TaskErrorEvent $event): void
    {
        $this->logger->critical('Error thrown while executing task "{task}". Message: "{message}"', [
            'exception' => $event->getError(),
            'task' => $event->getTaskName(),
            'message' => $event->getError()->getMessage(),
        ]);
    }
}
