<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Event;

use CrazyGoat\WorkermanBundle\Event\ProcessStartEvent;
use PHPUnit\Framework\TestCase;

final class ProcessStartEventTest extends TestCase
{
    public function testConstructAndGetters(): void
    {
        $event = new ProcessStartEvent('App\Process\MyProcess', 'my_process');

        self::assertSame('App\Process\MyProcess', $event->getServiceClass());
        self::assertSame('my_process', $event->getProcessName());
    }
}
