<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Scheduler;

use CrazyGoat\WorkermanBundle\Event\TaskErrorEvent;
use CrazyGoat\WorkermanBundle\Scheduler\TaskErrorListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TaskErrorListenerTest extends TestCase
{
    public function testOnExceptionLogsCriticalWithTaskNameAndThrowable(): void
    {
        $exception = new \RuntimeException('Something went wrong');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('critical')
            ->willReturnCallback(function (string $message, array $context) use ($exception): void {
                $this->assertSame('Error thrown while executing task "{task}". Message: "{message}"', $message);
                $this->assertSame($exception, $context['exception']);
                $this->assertSame('my_task', $context['task']);
                $this->assertSame('Something went wrong', $context['message']);
            });

        $listener = new TaskErrorListener($logger);
        $listener->onException(new TaskErrorEvent($exception, 'App\Service\MyService', 'my_task'));
    }

    public function testOnExceptionSubscribesToTaskErrorEvent(): void
    {
        $this->assertSame(
            [TaskErrorEvent::class => ['onException', -128]],
            TaskErrorListener::getSubscribedEvents(),
        );
    }
}
