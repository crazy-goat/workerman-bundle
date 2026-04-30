<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Tests\Scheduler;

use CrazyGoat\WorkermanBundle\Event\TaskErrorEvent;
use CrazyGoat\WorkermanBundle\Event\TaskStartEvent;
use CrazyGoat\WorkermanBundle\Scheduler\TaskHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class TaskHandlerTest extends TestCase
{
    public function testInvokeDispatchesStartEventAndCallsServiceMethod(): void
    {
        $service = new class {
            public bool $called = false;

            public function execute(): void
            {
                $this->called = true;
            }
        };

        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('get')->with('my_task_service')->willReturn($service);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(TaskStartEvent::class));

        $handler = new TaskHandler($locator, $eventDispatcher);
        $handler->__invoke('my_task_service::execute', 'test_task');

        $this->assertTrue($service->called);
    }

    public function testInvokeDispatchesErrorEventWhenMethodDoesNotExist(): void
    {
        $service = new class {
        };

        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('get')->with('my_task_service')->willReturn($service);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($event) {
                static $callCount = 0;
                ++$callCount;
                if ($callCount === 1) {
                    $this->assertInstanceOf(TaskStartEvent::class, $event);
                } elseif ($callCount === 2) {
                    $this->assertInstanceOf(TaskErrorEvent::class, $event);
                }

                return $event;
            });

        $handler = new TaskHandler($locator, $eventDispatcher);
        $handler->__invoke('my_task_service::nonexistent', 'test_task');
        // Should not throw - error is caught by try-catch and dispatched as event
    }

    public function testInvokeDispatchesErrorEventWhenServiceMethodThrowsException(): void
    {
        $service = new class {
            public function execute(): void
            {
                throw new \RuntimeException('Task failed');
            }
        };

        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('get')->with('my_task_service')->willReturn($service);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($event) {
                static $callCount = 0;
                ++$callCount;
                if ($callCount === 1) {
                    $this->assertInstanceOf(TaskStartEvent::class, $event);
                } elseif ($callCount === 2) {
                    $this->assertInstanceOf(TaskErrorEvent::class, $event);
                    $this->assertInstanceOf(\RuntimeException::class, $event->getError());
                    $this->assertSame('Task failed', $event->getError()->getMessage());
                }

                return $event;
            });

        $handler = new TaskHandler($locator, $eventDispatcher);
        $handler->__invoke('my_task_service::execute', 'test_task');
    }

    /** @dataProvider provideInvalidServiceStrings */
    public function testInvokeThrowsExceptionOnInvalidFormat(string $input): void
    {
        $locator = $this->createMock(ContainerInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid service method format');

        $handler = new TaskHandler($locator, $eventDispatcher);
        $handler->__invoke($input, 'test_task');
    }

    /** @return iterable<array{string}> */
    public static function provideInvalidServiceStrings(): iterable
    {
        yield 'missing separator' => ['JustAService'];
        yield 'empty service ID' => ['::method'];
        yield 'empty method name' => ['service::'];
        yield 'empty string' => [''];
    }
}
