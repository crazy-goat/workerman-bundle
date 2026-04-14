<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * Tests for Runner fork error handling.
 *
 * Issue #23: Runner::run() — Fork Without Error Handling
 *
 * Behavioral tests run in isolated PHP processes (via proc_open) to avoid
 * inheriting PHPUnit's output buffers and shutdown functions, which interfere
 * with pcntl_fork() + exit() behavior.
 *
 * The structural test verifies the Runner source code uses the correct
 * error handling pattern as a regression safety net.
 */
final class RunnerTest extends TestCase
{
    private const RUNNER_SCRIPT = __DIR__ . '/Fixtures/runner_test_runner.php';

    /**
     * Structural test: verify Runner source uses correct error handling pattern.
     */
    public function testRunnerUsesCorrectForkErrorHandling(): void
    {
        $sourceFile = dirname(__DIR__) . '/src/Runner.php';
        $this->assertFileExists($sourceFile);

        $content = file_get_contents($sourceFile);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            "throw new \RuntimeException('Failed to fork process for cache warmup')",
            $content,
            'Must throw when pcntl_fork() returns -1',
        );

        $this->assertStringContainsString(
            'pcntl_wifexited',
            $content,
            'Must check pcntl_wifexited() before pcntl_wexitstatus() to detect signal-killed children',
        );

        $this->assertStringContainsString(
            "throw new \RuntimeException('Cache warmup failed in forked process')",
            $content,
            'Must throw when child exits with non-zero code',
        );
    }

    /**
     * Test: Child process throws exception during boot.
     * Verifies that exception message is written to STDERR and child exits with code 1.
     */
    public function testChildBootExceptionWritesToStderrAndExitsNonZero(): void
    {
        $this->runIsolatedTest('child_boot_exception');
    }

    /**
     * Test: Child process exits normally with code 0.
     * Parent should not detect failure.
     */
    public function testChildNormalExitDoesNotTriggerFailure(): void
    {
        $this->runIsolatedTest('child_normal_exit');
    }

    /**
     * Test: Child process exits with non-zero code.
     * Parent should detect non-zero exit via pcntl_wifexited + pcntl_wexitstatus.
     */
    public function testChildExitNonzeroIsDetected(): void
    {
        $this->runIsolatedTest('child_exit_nonzero');
    }

    /**
     * Test: Child process is killed by signal.
     * Verifies pcntl_wifexited returns false for signal-killed children.
     */
    public function testSignalKilledChildIsDetected(): void
    {
        $this->runIsolatedTest('signal_killed_child');
    }

    /**
     * Run a test in an isolated PHP process to avoid PHPUnit
     * state inheritance issues with pcntl_fork().
     *
     * Uses `php -n` (no php.ini) to prevent the grpc extension from loading.
     * The grpc extension's shutdown handler deadlocks in forked child processes,
     * making exit() hang indefinitely. Only the posix extension is loaded
     * explicitly (pcntl is statically compiled).
     */
    private function runIsolatedTest(string $testName): void
    {
        $this->assertFileExists(self::RUNNER_SCRIPT, 'Test runner script must exist');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $extensionDir = ini_get('extension_dir');

        $process = proc_open(
            [
                PHP_BINARY,
                '-n',
                '-d', 'extension_dir=' . $extensionDir,
                '-d', 'extension=posix',
                self::RUNNER_SCRIPT,
                $testName,
            ],
            $descriptors,
            $pipes,
        );

        $this->assertIsResource($process, 'Failed to start isolated test process');

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $this->assertSame(
            0,
            $exitCode,
            sprintf(
                "Isolated test '%s' failed (exit code %d):\nstdout: %s\nstderr: %s",
                $testName,
                $exitCode,
                $stdout,
                $stderr,
            ),
        );

        $this->assertStringContainsString('PASS', $stdout);
    }
}
