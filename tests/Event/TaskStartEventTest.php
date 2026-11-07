<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Event;

use CrazyGoat\WorkermanBundle\Event\TaskStartEvent;
use PHPUnit\Framework\TestCase;

final class TaskStartEventTest extends TestCase
{
    public function testConstructAndGetters(): void
    {
        $event = new TaskStartEvent('App\Service\MyService', 'my_task');

        self::assertSame('App\Service\MyService', $event->getServiceClass());
        self::assertSame('my_task', $event->getTaskName());
    }
}
