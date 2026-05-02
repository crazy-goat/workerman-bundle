<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * Tests for SIGCHLD signal handling in SchedulerWorker.
 *
 * Issue #41: Ignored SIGCHLD Prevents Crash Detection
 *
 * Behavioral tests run in isolated PHP processes (via proc_open) to avoid
 * inheriting PHPUnit's output buffers and shutdown functions, which interfere
 * with pcntl_fork() + exit() behavior.
 *
 * The structural test verifies the SchedulerWorker source code uses the
 * correct signal handling pattern as a regression safety net.
 */
final class SchedulerWorkerSigchldTest extends TestCase
{
    private const RUNNER_SCRIPT = __DIR__ . '/Fixtures/sigchld_test_runner.php';

    /**
     * Test that the SIGCHLD handler detects a child exiting with non-zero code.
     */
    public function testSigchldHandlerDetectsNonZeroExitCode(): void
    {
        $this->runIsolatedTest('nonzero_exit');
    }

    /**
     * Test that a child exiting with code 0 does NOT produce a warning log.
     */
    public function testSigchldHandlerIgnoresZeroExitCode(): void
    {
        $this->runIsolatedTest('zero_exit');
    }

    /**
     * Test that a child killed by a signal is detected via pcntl_wifsignaled.
     * This is the primary scenario Issue #41 aims to detect (SIGSEGV, SIGKILL, OOM).
     */
    public function testSigchldHandlerDetectsSignalKilledChild(): void
    {
        $this->runIsolatedTest('signal_kill');
    }

    /**
     * Test that multiple simultaneous child terminations are all reaped.
     */
    public function testSigchldHandlerReapsMultipleChildren(): void
    {
        $this->runIsolatedTest('multiple_children');
    }

    /**
     * Structural test: verify SchedulerWorker source uses correct signal pattern.
     * This is a regression safety net — if someone reverts to SIG_IGN or removes
     * pcntl_wifsignaled, this test catches it immediately.
     */
    public function testSchedulerWorkerUsesCorrectSignalPattern(): void
    {
        $sourceFile = dirname(__DIR__) . '/src/Worker/SchedulerWorker.php';
        $this->assertFileExists($sourceFile);

        $content = file_get_contents($sourceFile);
        $this->assertNotFalse($content);

        $this->assertStringNotContainsString(
            'pcntl_signal(SIGCHLD, SIG_IGN)',
            $content,
            'SIGCHLD must not be ignored — this was the original bug (Issue #41)',
        );

        $this->assertStringContainsString(
            'pcntl_wifsignaled',
            $content,
            'Handler must detect signal-killed child processes',
        );

        $this->assertStringContainsString(
            'pcntl_wifexited',
            $content,
            'Handler must check if child exited normally before reading exit code',
        );
    }

    /**
     * Structural test: verify SchedulerWorker logs exceptions in child process.
     * This is a regression safety net for Issue #157 — if someone removes the
     * exception logging or reverts to silently swallowing exceptions, this test
     * catches it immediately.
     */
    public function testSchedulerWorkerLogsExceptionsInChildProcess(): void
    {
        $sourceFile = dirname(__DIR__) . '/src/Worker/SchedulerWorker.php';
        $this->assertFileExists($sourceFile);

        $content = file_get_contents($sourceFile);
        $this->assertNotFalse($content);

        // Verify the catch block captures the exception variable
        $this->assertStringContainsString(
            'catch (\Throwable $e)',
            $content,
            'Catch block must capture exception variable for logging (Issue #157)',
        );

        // Verify the exception is logged via Worker::log()
        $this->assertStringContainsString(
            '$this->worker->log(sprintf(',
            $content,
            'Exception must be logged via Worker::log() in child process (Issue #157)',
        );

        // Verify the log message includes exception type
        $this->assertStringContainsString(
            '$e::class',
            $content,
            'Log message must include exception type for quick identification (Issue #157)',
        );

        // Verify the log message includes stack trace
        $this->assertStringContainsString(
            '$e->getTraceAsString()',
            $content,
            'Log message must include stack trace for full diagnostic information (Issue #157)',
        );
    }

    /**
     * Run a SIGCHLD test in an isolated PHP process to avoid PHPUnit
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
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
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
