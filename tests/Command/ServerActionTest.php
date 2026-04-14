<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Command;

use CrazyGoat\WorkermanBundle\Command\ServerAction;
use PHPUnit\Framework\TestCase;

final class ServerActionTest extends TestCase
{
    public function testEnumCases(): void
    {
        self::assertEquals('start', ServerAction::START->value);
        self::assertEquals('stop', ServerAction::STOP->value);
        self::assertEquals('restart', ServerAction::RESTART->value);
        self::assertEquals('reload', ServerAction::RELOAD->value);
        self::assertEquals('status', ServerAction::STATUS->value);
        self::assertEquals('connections', ServerAction::CONNECTIONS->value);
    }

    public function testValues(): void
    {
        $values = ServerAction::values();

        self::assertCount(6, $values);
        self::assertContains('start', $values);
        self::assertContains('stop', $values);
        self::assertContains('restart', $values);
        self::assertContains('reload', $values);
        self::assertContains('status', $values);
        self::assertContains('connections', $values);
    }

    public function testTryFromValidValue(): void
    {
        $action = ServerAction::tryFrom('start');

        self::assertSame(ServerAction::START, $action);
    }

    public function testTryFromInvalidValue(): void
    {
        $action = ServerAction::tryFrom('invalid');

        self::assertNull($action);
    }
}
