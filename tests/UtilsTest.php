<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Utils;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CrazyGoat\WorkermanBundle\Utils
 */
final class UtilsTest extends TestCase
{
    public function testIsWindows(): void
    {
        $expected = \DIRECTORY_SEPARATOR !== '/';
        $this->assertSame($expected, Utils::isWindows());
    }

    public function testCpuCountReturnsPositiveInteger(): void
    {
        $cpuCount = Utils::cpuCount();

        $this->assertGreaterThanOrEqual(1, $cpuCount);
    }

    public function testCpuCountNeverReturnsZero(): void
    {
        // Regression test for #150: Utils::cpuCount() must NEVER return 0,
        // even when shell_exec('nproc') returns null (command not available)
        // or produces empty/unexpected output. Returning 0 would cause
        // downstream issues: zero workers spawned in ServerWorker.
        $this->assertNotSame(0, Utils::cpuCount());
    }

    /**
     * @requires OS Windows
     */
    public function testCpuCountReturnsOneOnWindows(): void
    {
        $this->assertSame(1, Utils::cpuCount());
    }

    /**
     * @requires OS Linux|Darwin
     */
    public function testCpuCountReturnsOneWhenShellExecDisabled(): void
    {
        if (function_exists('shell_exec')) {
            $this->markTestSkipped('shell_exec is available, cannot test disabled state');
        }

        $this->assertSame(1, Utils::cpuCount());
    }

    public function testClearOpcacheDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        Utils::clearOpcache();
    }

    public function testCannotBeInstantiated(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/(private|cannot be accessed)/');

        // PHPStan doesn't understand expectException() - this line is expected to throw
        /** @phpstan-ignore-next-line */
        new Utils();
    }

    public function testReloadSendsSigusr1(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            $this->markTestSkipped('pcntl and posix extensions are required for this test.');
        }

        $signalReceived = false;
        \pcntl_signal(SIGUSR1, static function () use (&$signalReceived): void {
            $signalReceived = true;
        });

        try {
            Utils::reload();
            \pcntl_signal_dispatch();
            $this->assertTrue($signalReceived, 'reload() should send SIGUSR1 signal.');
        } finally {
            \pcntl_signal(SIGUSR1, SIG_DFL);
        }
    }

    public function testDeprecatedRebootTriggersDeprecation(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            $this->markTestSkipped('pcntl and posix extensions are required for this test.');
        }

        \pcntl_signal(SIGUSR1, static fn(): null => null);

        $deprecationTriggered = false;
        \set_error_handler(
            static function (int $errno, string $errstr) use (&$deprecationTriggered): bool {
                if ($errno === \E_USER_DEPRECATED && \str_contains($errstr, 'Utils::reboot() is deprecated')) {
                    $deprecationTriggered = true;

                    return true;
                }

                return false;
            },
        );

        try {
            Utils::reboot();
            \pcntl_signal_dispatch();
            $this->assertTrue($deprecationTriggered, 'reboot() should trigger a deprecation notice.');
        } finally {
            \restore_error_handler();
            \pcntl_signal(SIGUSR1, SIG_DFL);
        }
    }

    public function testDeprecatedRebootDelegatesToReload(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            $this->markTestSkipped('pcntl and posix extensions are required for this test.');
        }

        $signalReceived = false;
        \pcntl_signal(SIGUSR1, static function () use (&$signalReceived): void {
            $signalReceived = true;
        });

        \set_error_handler(static fn(int $errno): bool => $errno === \E_USER_DEPRECATED);

        try {
            Utils::reboot();
            \pcntl_signal_dispatch();
            $this->assertTrue($signalReceived, 'reboot() should delegate to reload() and send SIGUSR1.');
        } finally {
            \restore_error_handler();
            \pcntl_signal(SIGUSR1, SIG_DFL);
        }
    }
}
