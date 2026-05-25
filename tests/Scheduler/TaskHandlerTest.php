<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Scheduler;

use CrazyGoat\WorkermanBundle\Event\TaskErrorEvent;
use CrazyGoat\WorkermanBundle\Event\TaskStartEvent;
use CrazyGoat\WorkermanBundle\Scheduler\TaskHandler;
use CrazyGoat\WorkermanBundle\Util\ServiceMethod;
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
        $handler->__invoke(new ServiceMethod('my_task_service', 'execute'), 'test_task');

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
        $handler->__invoke(new ServiceMethod('my_task_service', 'nonexistent'), 'test_task');
        // Should not throw - error is caught by try-catch and dispatched as event
    }

    public function testInvokeDispatchesErrorEventWhenServiceMethodThrowsException(): void
    {
        $service = new class {
            public function execute(): never
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
        $handler->__invoke(new ServiceMethod('my_task_service', 'execute'), 'test_task');
    }
}
