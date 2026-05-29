<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Event;

use CrazyGoat\WorkermanBundle\Event\TaskErrorEvent;
use PHPUnit\Framework\TestCase;

final class TaskErrorEventTest extends TestCase
{
    public function testConstructAndGetters(): void
    {
        $exception = new \RuntimeException('test error');
        $event = new TaskErrorEvent($exception, 'App\Service\MyService', 'my_task');

        self::assertSame($exception, $event->getError());
        self::assertSame('App\Service\MyService', $event->getServiceClass());
        self::assertSame('my_task', $event->getTaskName());
    }

    public function testEventIsImmutable(): void
    {
        $reflection = new \ReflectionClass(TaskErrorEvent::class);

        self::assertFalse(
            $reflection->hasMethod('setError'),
            'TaskErrorEvent should not have a setError mutator',
        );

        $property = $reflection->getProperty('error');
        self::assertTrue($property->isReadOnly());
    }
}
