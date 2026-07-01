<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\ConfigLoader;
use CrazyGoat\WorkermanBundle\StatusFileReader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for StatusFileReader file polling and status file path behavior.
 */
final class StatusFileReaderTest extends TestCase
{
    private function createEmptyConfigLoader(): ConfigLoader
    {
        return new ConfigLoader(
            projectDir: sys_get_temp_dir(),
            cacheDir: sys_get_temp_dir(),
            isDebug: false,
        );
    }

    /** @param array<string, mixed> $config */
    private function createConfigLoaderWithConfig(array $config): ConfigLoader
    {
        $configLoader = $this->createEmptyConfigLoader();
        $configLoader->setWorkermanConfig($config);

        return $configLoader;
    }

    private function createReader(ConfigLoader $configLoader): StatusFileReader
    {
        return new StatusFileReader($configLoader);
    }

    /**
     * Invoke the private waitForFile method via reflection.
     */
    private function invokeWaitForFile(StatusFileReader $reader, string $filePath, int $timeout): bool
    {
        $reflection = new ReflectionClass($reader);
        $method = $reflection->getMethod('waitForFile');

        return $method->invoke($reader, $filePath, $timeout);
    }

    /**
     * Invoke the private getStatusTimeout method via reflection.
     */
    private function invokeGetStatusTimeout(StatusFileReader $reader): int
    {
        $reflection = new ReflectionClass($reader);
        $method = $reflection->getMethod('getStatusTimeout');

        return $method->invoke($reader);
    }

    // ----- waitForFile tests -----

    public function testWaitForFileReturnsTrueWhenFileExists(): void
    {
        $reader = $this->createReader($this->createEmptyConfigLoader());

        $tempFile = tempnam(sys_get_temp_dir(), 'workerman_test_');
        file_put_contents($tempFile, 'status data');
        register_shutdown_function(static function () use ($tempFile): void {
            @unlink($tempFile);
        });

        $startTime = microtime(true);
        $result = $this->invokeWaitForFile($reader, $tempFile, 5);
        $elapsed = microtime(true) - $startTime;

        $this->assertTrue($result, 'waitForFile should return true when file already exists and has content');
        $this->assertLessThan(1, $elapsed, 'Should return nearly instantly for existing file');
    }

    public function testWaitForFileReturnsFalseOnTimeout(): void
    {
        $reader = $this->createReader($this->createEmptyConfigLoader());

        $nonExistentFile = sys_get_temp_dir() . '/workerman_test_nonexistent_' . uniqid();

        $startTime = microtime(true);
        $result = $this->invokeWaitForFile($reader, $nonExistentFile, 0);
        $elapsed = microtime(true) - $startTime;

        $this->assertFalse($result, 'waitForFile should return false when file never appears');
        $this->assertLessThan(1, $elapsed, 'Should time out quickly with timeout=0');
    }

    public function testWaitForFileReturnsFalseWhenFileEmpty(): void
    {
        $reader = $this->createReader($this->createEmptyConfigLoader());

        $tempFile = tempnam(sys_get_temp_dir(), 'workerman_test_empty_');
        register_shutdown_function(static function () use ($tempFile): void {
            @unlink($tempFile);
        });

        $startTime = microtime(true);
        $result = $this->invokeWaitForFile($reader, $tempFile, 1);
        $elapsed = microtime(true) - $startTime;

        $this->assertFalse($result, 'waitForFile should return false when file is empty (0 bytes)');
        $this->assertGreaterThanOrEqual(0.5, $elapsed, 'Should approach the 1s timeout waiting for content');
        $this->assertLessThan(2, $elapsed, 'Should not exceed timeout by much');
    }

    /**
     * @requires extension pcntl
     */
    public function testWaitForFileReturnsTrueWhenFileAppearsDuringPolling(): void
    {
        $reader = $this->createReader($this->createEmptyConfigLoader());

        $tempFile = sys_get_temp_dir() . '/workerman_test_appears_' . uniqid();
        register_shutdown_function(static function () use ($tempFile): void {
            @unlink($tempFile);
        });

        $pid = pcntl_fork();
        if ($pid === 0) {
            usleep(100_000);
            file_put_contents($tempFile, 'status data');
            posix_kill(posix_getpid(), SIGKILL);
        }

        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        $startTime = microtime(true);
        $result = $this->invokeWaitForFile($reader, $tempFile, 5);
        $elapsed = microtime(true) - $startTime;

        pcntl_waitpid($pid, $status);

        $this->assertTrue($result, 'waitForFile should return true when file appears during polling');
        $this->assertGreaterThan(0.05, $elapsed, 'Should take at least 50ms (file created at 100ms)');
        $this->assertLessThan(3, $elapsed, 'Should complete well within 5s timeout');
    }

    // ----- getStatusTimeout tests -----

    public function testGetStatusTimeoutDefault(): void
    {
        $reader = $this->createReader($this->createConfigLoaderWithConfig(['stop_timeout' => 2]));

        $timeout = $this->invokeGetStatusTimeout($reader);

        $this->assertSame(5, $timeout, 'Default status timeout should be 5 seconds when not configured');
    }

    public function testGetStatusTimeoutFromConfig(): void
    {
        $reader = $this->createReader($this->createConfigLoaderWithConfig(['status_timeout' => 10, 'stop_timeout' => 2]));

        $timeout = $this->invokeGetStatusTimeout($reader);

        $this->assertSame(10, $timeout, 'Should read status_timeout from config');
    }
}
