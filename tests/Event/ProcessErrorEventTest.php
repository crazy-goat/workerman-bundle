<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Event;

use CrazyGoat\WorkermanBundle\Event\ProcessErrorEvent;
use PHPUnit\Framework\TestCase;

final class ProcessErrorEventTest extends TestCase
{
    public function testConstructAndGetters(): void
    {
        $exception = new \RuntimeException('test error');
        $event = new ProcessErrorEvent($exception, 'App\Service\MyService', 'my_process');

        self::assertSame($exception, $event->getError());
        self::assertSame('App\Service\MyService', $event->getServiceClass());
        self::assertSame('my_process', $event->getProcessName());
    }

    public function testSetErrorReplacesTheError(): void
    {
        $original = new \RuntimeException('original error');
        $replacement = new \LogicException('replacement error');
        $event = new ProcessErrorEvent($original, 'App\Service\MyService', 'my_process');

        $event->setError($replacement);

        self::assertSame($replacement, $event->getError());
        self::assertNotSame($original, $event->getError());
    }
}
