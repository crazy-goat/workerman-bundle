<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Supervisor;

use CrazyGoat\WorkermanBundle\Event\ProcessErrorEvent;
use CrazyGoat\WorkermanBundle\Event\ProcessStartEvent;
use CrazyGoat\WorkermanBundle\Supervisor\ProcessHandler;
use CrazyGoat\WorkermanBundle\Test\EventDispatcher\RecordingEventDispatcher;
use CrazyGoat\WorkermanBundle\Util\ServiceMethod;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ProcessHandlerTest extends TestCase
{
    public function testInvokeDispatchesStartEventAndCallsServiceMethod(): void
    {
        $service = new class {
            public bool $called = false;

            public function run(): void
            {
                $this->called = true;
            }
        };

        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('get')->with('my_service')->willReturn($service);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ProcessStartEvent::class));

        $handler = new ProcessHandler($locator, $eventDispatcher);
        $handler->__invoke(new ServiceMethod('my_service', 'run'), 'test_process');

        $this->assertTrue($service->called);
    }

    public function testInvokeDispatchesErrorEventWhenMethodDoesNotExist(): void
    {
        $service = new class {
        };

        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('get')->with('my_service')->willReturn($service);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($event) {
                static $callCount = 0;
                ++$callCount;
                if ($callCount === 1) {
                    $this->assertInstanceOf(ProcessStartEvent::class, $event);
                } elseif ($callCount === 2) {
                    $this->assertInstanceOf(ProcessErrorEvent::class, $event);
                }

                return $event;
            });

        $handler = new ProcessHandler($locator, $eventDispatcher);
        $handler->__invoke(new ServiceMethod('my_service', 'nonexistent'), 'test_process');
    }

    public function testInvokeDispatchesErrorEventWhenServiceMethodThrowsException(): void
    {
        $service = new class {
            public function run(): never
            {
                throw new \RuntimeException('Something went wrong');
            }
        };

        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('get')->with('my_service')->willReturn($service);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($event) {
                static $callCount = 0;
                ++$callCount;
                if ($callCount === 1) {
                    $this->assertInstanceOf(ProcessStartEvent::class, $event);
                } elseif ($callCount === 2) {
                    $this->assertInstanceOf(ProcessErrorEvent::class, $event);
                    $this->assertInstanceOf(\RuntimeException::class, $event->getError());
                    $this->assertSame('Something went wrong', $event->getError()->getMessage());
                }

                return $event;
            });

        $handler = new ProcessHandler($locator, $eventDispatcher);
        $handler->__invoke(new ServiceMethod('my_service', 'run'), 'test_process');
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

        $handler = new ProcessHandler($locator, $eventDispatcher);
        $handler->__invoke(new ServiceMethod('my_invokable_service', '__invoke'), 'test_process');

        $this->assertTrue($service->called);
        $this->assertCount(1, $eventDispatcher->events);
        $this->assertInstanceOf(ProcessStartEvent::class, $eventDispatcher->events[0]);
    }

    public function testEventOrderingWhenServiceMethodThrowsException(): void
    {
        $service = new class {
            public function run(): never
            {
                throw new \RuntimeException('Something went wrong');
            }
        };

        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('get')->with('my_service')->willReturn($service);

        $eventDispatcher = new RecordingEventDispatcher();

        $handler = new ProcessHandler($locator, $eventDispatcher);
        $handler->__invoke(new ServiceMethod('my_service', 'run'), 'test_process');

        $this->assertCount(2, $eventDispatcher->events);
        $this->assertInstanceOf(ProcessStartEvent::class, $eventDispatcher->events[0]);
        $this->assertInstanceOf(ProcessErrorEvent::class, $eventDispatcher->events[1]);
        $this->assertInstanceOf(\RuntimeException::class, $eventDispatcher->events[1]->getError());
        $this->assertSame('Something went wrong', $eventDispatcher->events[1]->getError()->getMessage());
    }

    public function testEventOrderingWhenMethodDoesNotExist(): void
    {
        $service = new class {
        };

        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('get')->with('my_service')->willReturn($service);

        $eventDispatcher = new RecordingEventDispatcher();

        $handler = new ProcessHandler($locator, $eventDispatcher);
        $handler->__invoke(new ServiceMethod('my_service', 'nonexistent'), 'test_process');

        $this->assertCount(2, $eventDispatcher->events);
        $this->assertInstanceOf(ProcessStartEvent::class, $eventDispatcher->events[0]);
        $this->assertInstanceOf(ProcessErrorEvent::class, $eventDispatcher->events[1]);
        $this->assertInstanceOf(\InvalidArgumentException::class, $eventDispatcher->events[1]->getError());
    }
}
