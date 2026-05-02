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

    public function testReboot(): void
    {
        $this->markTestSkipped(
            'Utils::reboot() sends POSIX signals and requires a running Workerman process. ' .
            'Cannot be tested in unit test context — requires integration test.',
        );
    }
}
