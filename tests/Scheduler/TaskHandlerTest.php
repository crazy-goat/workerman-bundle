<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Scheduler;

use CrazyGoat\WorkermanBundle\Event\TaskErrorEvent;
use CrazyGoat\WorkermanBundle\Event\TaskStartEvent;
use CrazyGoat\WorkermanBundle\Scheduler\TaskHandler;
use CrazyGoat\WorkermanBundle\Test\EventDispatcher\RecordingEventDispatcher;
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

    public function testInvokeCallsMagicInvokeMethod(): void
    {
        $service = new class {
            public bool $called = false;

            public function __invoke(): void
            {
                $this->called = true;
            }
        };

        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('get')->with('my_invokable_service')->willReturn($service);

        $eventDispatcher = new RecordingEventDispatcher();

        $handler = new TaskHandler($locator, $eventDispatcher);
        $handler->__invoke(new ServiceMethod('my_invokable_service', '__invoke'), 'test_task');

        $this->assertTrue($service->called);
        $this->assertCount(1, $eventDispatcher->events);
        $this->assertInstanceOf(TaskStartEvent::class, $eventDispatcher->events[0]);
    }

    public function testEventOrderingWhenServiceMethodThrowsException(): void
    {
        $service = new class {
            public function execute(): never
            {
                throw new \RuntimeException('Task failed');
            }
        };

        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('get')->with('my_task_service')->willReturn($service);

        $eventDispatcher = new RecordingEventDispatcher();

        $handler = new TaskHandler($locator, $eventDispatcher);
        $handler->__invoke(new ServiceMethod('my_task_service', 'execute'), 'test_task');

        $this->assertCount(2, $eventDispatcher->events);
        $this->assertInstanceOf(TaskStartEvent::class, $eventDispatcher->events[0]);
        $this->assertInstanceOf(TaskErrorEvent::class, $eventDispatcher->events[1]);
        $this->assertInstanceOf(\RuntimeException::class, $eventDispatcher->events[1]->getError());
        $this->assertSame('Task failed', $eventDispatcher->events[1]->getError()->getMessage());
    }

    public function testEventOrderingWhenMethodDoesNotExist(): void
    {
        $service = new class {
        };

        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('get')->with('my_task_service')->willReturn($service);

        $eventDispatcher = new RecordingEventDispatcher();

        $handler = new TaskHandler($locator, $eventDispatcher);
        $handler->__invoke(new ServiceMethod('my_task_service', 'nonexistent'), 'test_task');

        $this->assertCount(2, $eventDispatcher->events);
        $this->assertInstanceOf(TaskStartEvent::class, $eventDispatcher->events[0]);
        $this->assertInstanceOf(TaskErrorEvent::class, $eventDispatcher->events[1]);
        $this->assertInstanceOf(\InvalidArgumentException::class, $eventDispatcher->events[1]->getError());
    }

    public function testMultipleInvocationsWithSameServiceMethodWorkCorrectly(): void
    {
        $service = new class {
            public int $count = 0;

            public function execute(): void
            {
                ++$this->count;
            }
        };

        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('get')->with('my_task_service')->willReturn($service);

        $eventDispatcher = new RecordingEventDispatcher();

        $handler = new TaskHandler($locator, $eventDispatcher);

        $serviceMethod = new ServiceMethod('my_task_service', 'execute');
        $handler->__invoke($serviceMethod, 'test_task');
        $handler->__invoke($serviceMethod, 'test_task');
        $handler->__invoke($serviceMethod, 'test_task');

        $this->assertSame(3, $service->count);
        $this->assertCount(3, $eventDispatcher->events);
    }

    public function testMultipleInvocationsWithDifferentServiceMethodsWorkCorrectly(): void
    {
        $service = new class {
            public int $callCount = 0;

            public function first(): void
            {
                ++$this->callCount;
            }

            public function second(): void
            {
                ++$this->callCount;
            }
        };

        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('get')->with('my_task_service')->willReturn($service);

        $eventDispatcher = new RecordingEventDispatcher();

        $handler = new TaskHandler($locator, $eventDispatcher);

        $handler->__invoke(new ServiceMethod('my_task_service', 'first'), 'test_task');
        $handler->__invoke(new ServiceMethod('my_task_service', 'second'), 'test_task');
        $handler->__invoke(new ServiceMethod('my_task_service', 'first'), 'test_task');

        $this->assertSame(3, $service->callCount);
        $this->assertCount(3, $eventDispatcher->events);
    }
}
