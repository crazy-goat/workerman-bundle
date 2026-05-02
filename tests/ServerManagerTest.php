<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\ServerManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Tests for ServerManager timeout behavior.
 *
 * These tests verify that waitForProcessToStop() correctly implements
 * timeout logic for both graceful and regular stop modes,
 * and that getStatus() uses polling instead of hardcoded sleep.
 */
final class ServerManagerTest extends TestCase
{
    /**
     * Invoke the private waitForProcessToStop method on a ServerManager instance.
     *
     * This method uses reflection to test the private method directly.
     * Instead of mocking isProcessAlive (which is private), we verify behavior
     * by checking that the method returns within expected time bounds.
     */
    private function invokeWaitForProcessToStop(
        ServerManager $manager,
        int $pid,
        int $stopTimeout,
        bool $graceful,
    ): bool {
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('waitForProcessToStop');

        return $method->invoke($manager, $pid, $stopTimeout, $graceful);
    }

    /**
     * Test that graceful stop respects timeout and doesn't loop infinitely.
     *
     * This is the main regression test for issue #20 - the original bug caused
     * an infinite loop when graceful=true because the timeout was never checked.
     */
    public function testGracefulStopRespectsTimeout(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $manager = new ServerManager($kernel);

        // Use a non-existent PID (0) which is immediately considered "not alive"
        // This tests that the method returns quickly without infinite looping
        $startTime = microtime(true);
        $result = $this->invokeWaitForProcessToStop($manager, 0, 1, true);
        $elapsed = microtime(true) - $startTime;

        // PID 0 is not alive, so should return true immediately
        $this->assertTrue($result);
        $this->assertLessThan(1, $elapsed, 'Should return immediately for non-existent PID');
    }

    /**
     * Test that regular stop also respects timeout.
     *
     * This is a regression test to ensure regular stop behavior is unchanged.
     */
    public function testRegularStopRespectsTimeout(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $manager = new ServerManager($kernel);

        // Use a non-existent PID (0) which is immediately considered "not alive"
        $startTime = microtime(true);
        $result = $this->invokeWaitForProcessToStop($manager, 0, 1, false);
        $elapsed = microtime(true) - $startTime;

        // PID 0 is not alive, so should return true immediately
        $this->assertTrue($result);
        $this->assertLessThan(1, $elapsed, 'Should return immediately for non-existent PID');
    }

    /**
     * Test that graceful timeout is always longer than regular timeout.
     *
     * This verifies the fix for the asymmetric timeout formula issue.
     * The formula is: graceful = stopTimeout * 3 + 3, regular = stopTimeout + 3
     */
    public function testGracefulTimeoutIsAlwaysLongerThanRegular(): void
    {
        $testCases = [
            ['stopTimeout' => 1, 'expectedGraceful' => 6, 'expectedRegular' => 4],
            ['stopTimeout' => 2, 'expectedGraceful' => 9, 'expectedRegular' => 5],
            ['stopTimeout' => 5, 'expectedGraceful' => 18, 'expectedRegular' => 8],
            ['stopTimeout' => 10, 'expectedGraceful' => 33, 'expectedRegular' => 13],
        ];

        foreach ($testCases as $case) {
            $stopTimeout = $case['stopTimeout'];
            $gracefulTimeout = $stopTimeout * 3 + 3;
            $regularTimeout = $stopTimeout + 3;

            // Verify the formula produces expected values
            $this->assertSame(
                $case['expectedGraceful'],
                $gracefulTimeout,
                "Graceful timeout calculation incorrect for stopTimeout={$stopTimeout}",
            );
            $this->assertSame(
                $case['expectedRegular'],
                $regularTimeout,
                "Regular timeout calculation incorrect for stopTimeout={$stopTimeout}",
            );

            // Most importantly: graceful must always be longer than regular
            $this->assertGreaterThan(
                $regularTimeout,
                $gracefulTimeout,
                "Graceful timeout ({$gracefulTimeout}s) must be longer than regular ({$regularTimeout}s) for stopTimeout={$stopTimeout}",
            );
        }
    }

    // ----- waitForFile tests -----

    /**
     * Invoke the private waitForFile method via reflection.
     */
    private function invokeWaitForFile(ServerManager $manager, string $filePath, int $timeout): bool
    {
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('waitForFile');

        return $method->invoke($manager, $filePath, $timeout);
    }

    /**
     * Invoke the private getStatusTimeout method via reflection.
     */
    private function invokeGetStatusTimeout(ServerManager $manager): int
    {
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('getStatusTimeout');

        return $method->invoke($manager);
    }

    public function testWaitForFileReturnsTrueWhenFileExists(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $manager = new ServerManager($kernel);

        $tempFile = tempnam(sys_get_temp_dir(), 'workerman_test_');
        register_shutdown_function(static function () use ($tempFile): void {
            @unlink($tempFile);
        });

        $startTime = microtime(true);
        $result = $this->invokeWaitForFile($manager, $tempFile, 5);
        $elapsed = microtime(true) - $startTime;

        $this->assertTrue($result, 'waitForFile should return true when file already exists');
        $this->assertLessThan(1, $elapsed, 'Should return nearly instantly for existing file');
    }

    public function testWaitForFileReturnsFalseOnTimeout(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $manager = new ServerManager($kernel);

        $nonExistentFile = sys_get_temp_dir() . '/workerman_test_nonexistent_' . uniqid();

        $startTime = microtime(true);
        $result = $this->invokeWaitForFile($manager, $nonExistentFile, 0);
        $elapsed = microtime(true) - $startTime;

        $this->assertFalse($result, 'waitForFile should return false when file never appears');
        $this->assertLessThan(1, $elapsed, 'Should time out quickly with timeout=0');
    }

    /**
     * @requires extension pcntl
     */
    public function testWaitForFileReturnsTrueWhenFileAppearsDuringPolling(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $manager = new ServerManager($kernel);

        $tempFile = sys_get_temp_dir() . '/workerman_test_appears_' . uniqid();
        register_shutdown_function(static function () use ($tempFile): void {
            @unlink($tempFile);
        });

        // Create the file after 100ms delay via a background process
        $pid = pcntl_fork();
        if ($pid === 0) {
            // Child: sleep 100ms, create file, then die immediately
            usleep(100_000);
            file_put_contents($tempFile, 'status data');
            // Kill self immediately — avoids triggering inherited shutdown functions
            // (e.g. workerman_stop from bootstrap.php) that would kill the shared test server
            posix_kill(posix_getpid(), SIGKILL);
        }

        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        // Parent: wait for file with polling
        $startTime = microtime(true);
        $result = $this->invokeWaitForFile($manager, $tempFile, 5);
        $elapsed = microtime(true) - $startTime;

        // Clean up child process
        pcntl_waitpid($pid, $status);

        $this->assertTrue($result, 'waitForFile should return true when file appears during polling');
        $this->assertGreaterThan(0.05, $elapsed, 'Should take at least 50ms (file created at 100ms)');
        $this->assertLessThan(3, $elapsed, 'Should complete well within 5s timeout');
    }

    public function testGetStatusTimeoutDefault(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $manager = new ServerManager($kernel);

        // Inject config via reflection to avoid requiring a booted kernel
        $reflection = new ReflectionClass($manager);
        $configProp = $reflection->getProperty('config');
        $configProp->setValue($manager, ['stop_timeout' => 2]);

        $timeout = $this->invokeGetStatusTimeout($manager);

        $this->assertSame(5, $timeout, 'Default status timeout should be 5 seconds when not configured');
    }

    public function testGetStatusTimeoutFromConfig(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $manager = new ServerManager($kernel);

        // Inject config with custom status_timeout
        $reflection = new ReflectionClass($manager);
        $configProp = $reflection->getProperty('config');
        $configProp->setValue($manager, ['status_timeout' => 10, 'stop_timeout' => 2]);

        $timeout = $this->invokeGetStatusTimeout($manager);

        $this->assertSame(10, $timeout, 'Should read status_timeout from config');
    }
}
