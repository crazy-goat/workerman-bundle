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
    private const RUNNER_SOURCE = __DIR__ . '/../src/Runner.php';

    /**
     * Structural test: verify Runner source uses correct error handling pattern.
     */
    public function testRunnerUsesCorrectForkErrorHandling(): void
    {
        $sourceFile = self::RUNNER_SOURCE;
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
            'Cache warmup failed in forked process (exit code',
            $content,
            'Must throw with exit code when child exits with non-zero code',
        );

        $this->assertStringContainsString(
            'Cache warmup failed in forked process (child signaled failure via SIGTERM)',
            $content,
            'Must throw with SIGTERM-specific message when child signals failure',
        );

        $this->assertStringContainsString(
            'Cache warmup failed in forked process (killed by unexpected signal',
            $content,
            'Must throw with signal number when child is killed by unexpected signal',
        );

        $this->assertStringContainsString(
            'Cache warmup failed in forked process (unexpected status',
            $content,
            'Must throw with unexpected status when child neither exited nor signaled',
        );

        $this->assertStringContainsString(
            'SIGKILL',
            $content,
            'Must use SIGKILL for success signal',
        );

        $this->assertStringContainsString(
            'SIGTERM',
            $content,
            'Must use SIGTERM for error signal',
        );

        $this->assertStringContainsString(
            'Cache warmup timed out',
            $content,
            'Must have timeout error message',
        );

        $this->assertStringContainsString(
            'getCacheWarmupTimeout',
            $content,
            'Must have getCacheWarmupTimeout method',
        );

        $this->assertStringContainsString(
            'WORKERMAN_CACHE_WARMUP_TIMEOUT',
            $content,
            'Must support WORKERMAN_CACHE_WARMUP_TIMEOUT env var override',
        );

        $this->assertStringContainsString(
            'return 30;',
            $content,
            'Must have 30 second default timeout in getCacheWarmupTimeout',
        );

        $this->assertStringContainsString(
            'Unable to create directory',
            $content,
            'Must throw when mkdir() fails to create var/run directory',
        );
    }

    /**
     * Structural test: verify getCacheWarmupTimeout defaults to 30 seconds.
     */
    public function testCacheWarmupTimeoutDefaultsTo30(): void
    {
        $sourceFile = self::RUNNER_SOURCE;
        $this->assertFileExists($sourceFile);

        $content = file_get_contents($sourceFile);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            'private function getCacheWarmupTimeout(): int',
            $content,
            'Must have getCacheWarmupTimeout method',
        );

        $this->assertStringContainsString(
            'return 30;',
            $content,
            'Default cache warmup timeout must be 30 seconds',
        );

        $this->assertStringContainsString(
            "\$_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT']",
            $content,
            'Must check WORKERMAN_CACHE_WARMUP_TIMEOUT env var',
        );

        $this->assertStringContainsString(
            "throw new \InvalidArgumentException('WORKERMAN_CACHE_WARMUP_TIMEOUT must be a positive integer')",
            $content,
            'Must throw InvalidArgumentException for non-positive timeout',
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
     * Test: Child process uses posix_kill(getmypid(), SIGKILL) for success.
     * Verifies parent recognizes SIGKILL as success (cache warmup completed).
     */
    public function testChildSuccessSigkillIsRecognizedAsSuccess(): void
    {
        $this->runIsolatedTest('child_success_sigkill');
    }

    /**
     * Test: Child process uses posix_kill(getmypid(), SIGTERM) for error.
     * Verifies parent recognizes SIGTERM as failure (cache warmup failed).
     */
    public function testChildErrorSigtermIsRecognizedAsError(): void
    {
        $this->runIsolatedTest('child_error_sigterm');
    }

    /**
     * Test: Timeout kills child that doesn't finish in time.
     * Verifies parent correctly waits with WNOHANG and kills child on timeout.
     */
    public function testTimeoutKillsChild(): void
    {
        $this->runIsolatedTest('timeout_kills_child');
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
                self::RUNNER_SOURCE,
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
