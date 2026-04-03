<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Utils;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Utils class.
 *
 * @covers \CrazyGoat\WorkermanBundle\Utils
 */
final class UtilsTest extends TestCase
{
    /**
     * Test that isWindows() correctly identifies Windows systems.
     */
    public function testIsWindows(): void
    {
        // We can't mock PHP_OS or DIRECTORY_SEPARATOR, so we test the actual behavior
        $expected = \DIRECTORY_SEPARATOR !== '/';
        $this->assertSame($expected, Utils::isWindows());
    }

    /**
     * Test that cpuCount() returns a positive integer.
     */
    public function testCpuCountReturnsPositiveInteger(): void
    {
        $cpuCount = Utils::cpuCount();

        $this->assertIsInt($cpuCount);
        $this->assertGreaterThanOrEqual(1, $cpuCount);
    }

    /**
     * Test that cpuCount() returns 1 on Windows.
     */
    public function testCpuCountReturnsOneOnWindows(): void
    {
        if (!Utils::isWindows()) {
            $this->markTestSkipped('This test only runs on Windows');
        }

        $this->assertSame(1, Utils::cpuCount());
    }

    /**
     * Test that cpuCount() returns 1 when shell_exec is disabled.
     */
    public function testCpuCountReturnsOneWhenShellExecDisabled(): void
    {
        if (Utils::isWindows()) {
            $this->markTestSkipped('This test does not run on Windows');
        }

        if (function_exists('shell_exec')) {
            $this->markTestSkipped('shell_exec is available, cannot test disabled state');
        }

        $this->assertSame(1, Utils::cpuCount());
    }

    /**
     * Test that clearOpcache() does not throw exceptions.
     *
     * Note: We can't easily test if opcache was actually cleared without
     * loading files into opcache first, but we can verify the method
     * executes without errors.
     */
    public function testClearOpcacheDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        Utils::clearOpcache();
    }

    /**
     * Test that Utils class cannot be instantiated.
     */
    public function testCannotBeInstantiated(): void
    {
        $this->expectException(\Error::class);
        new Utils();
    }
}
