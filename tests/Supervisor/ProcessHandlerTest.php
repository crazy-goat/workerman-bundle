<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Tests\Supervisor;

use CrazyGoat\WorkermanBundle\Event\ProcessErrorEvent;
use CrazyGoat\WorkermanBundle\Event\ProcessStartEvent;
use CrazyGoat\WorkermanBundle\Supervisor\ProcessHandler;
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
        $handler->__invoke('my_service::run', 'test_process');

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
        $handler->__invoke('my_service::nonexistent', 'test_process');
        // Should not throw - error is caught by try-catch and dispatched as event
    }

    public function testInvokeDispatchesErrorEventWhenServiceMethodThrowsException(): void
    {
        $service = new class {
            public function run(): void
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
        $handler->__invoke('my_service::run', 'test_process');
    }

    /** @dataProvider provideInvalidServiceStrings */
    public function testInvokeThrowsExceptionOnInvalidFormat(string $input): void
    {
        $locator = $this->createMock(ContainerInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid service method format');

        $handler = new ProcessHandler($locator, $eventDispatcher);
        $handler->__invoke($input, 'test_process');
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
