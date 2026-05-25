<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Supervisor;

use CrazyGoat\WorkermanBundle\Event\ProcessErrorEvent;
use CrazyGoat\WorkermanBundle\Supervisor\ProcessErrorListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProcessErrorListenerTest extends TestCase
{
    public function testOnExceptionLogsCriticalWithProcessNameAndThrowable(): void
    {
        $exception = new \RuntimeException('Process failed');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('critical')
            ->willReturnCallback(function (string $message, array $context) use ($exception): void {
                $this->assertSame('Error thrown while executing process "{process}". Message: "{message}"', $message);
                $this->assertSame($exception, $context['exception']);
                $this->assertSame('my_process', $context['process']);
                $this->assertSame('Process failed', $context['message']);
            });

        $listener = new ProcessErrorListener($logger);
        $listener->onException(new ProcessErrorEvent($exception, 'App\Service\MyService', 'my_process'));
    }

    public function testOnExceptionSubscribesToProcessErrorEvent(): void
    {
        $this->assertSame(
            [ProcessErrorEvent::class => ['onException', -128]],
            ProcessErrorListener::getSubscribedEvents(),
        );
    }
}
