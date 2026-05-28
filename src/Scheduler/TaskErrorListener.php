<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Scheduler;

use CrazyGoat\WorkermanBundle\Event\TaskErrorEvent;
use CrazyGoat\WorkermanBundle\Handler\ServiceErrorListenerTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class TaskErrorListener implements EventSubscriberInterface
{
    use ServiceErrorListenerTrait;

    public function __construct(private LoggerInterface $logger)
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
        $this->logServiceError(
            'Error thrown while executing task "{task}". Message: "{message}"',
            'task',
            $event->getTaskName(),
            $event->getError()->getMessage(),
            $event->getError(),
        );
    }
}
