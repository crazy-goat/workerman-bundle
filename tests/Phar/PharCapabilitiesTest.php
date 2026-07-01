<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Phar;

use CrazyGoat\WorkermanBundle\Phar\PharCapabilities;
use PHPUnit\Framework\TestCase;

final class PharCapabilitiesTest extends TestCase
{
    public function testProbeReflectsLiveRuntime(): void
    {
        $capabilities = PharCapabilities::probe();

        self::assertSame((bool) ini_get('phar.readonly'), $capabilities->isPharReadOnly());
        self::assertSame(class_exists(\Phar::class), $capabilities->isPharExtensionLoaded());
    }

    public function testAssertCanBuildSucceedsWhenCapabilitiesAreSatisfied(): void
    {
        $capabilities = new PharCapabilities(false, true);

        $capabilities->assertCanBuild();

        $this->expectNotToPerformAssertions();
    }

    public function testAssertCanBuildThrowsWhenPharReadOnlyIsEnabled(): void
    {
        $capabilities = new PharCapabilities(true, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('phar.readonly must be disabled');

        $capabilities->assertCanBuild();
    }

    public function testAssertCanBuildThrowsWhenPharExtensionIsMissing(): void
    {
        $capabilities = new PharCapabilities(false, false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Phar extension is required');

        $capabilities->assertCanBuild();
    }

    public function testAssertCanBuildThrowsReadOnlyBeforeExtensionCheck(): void
    {
        $capabilities = new PharCapabilities(true, false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('phar.readonly must be disabled');

        $capabilities->assertCanBuild();
    }
}
