<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

final class SchedulerWorkerSigchldTest extends TestCase
{
    private const RUNNER_SCRIPT = __DIR__ . '/Fixtures/sigchld_test_runner.php';

    public function testSigchldHandlerDetectsNonZeroExitCode(): void
    {
        $this->runIsolatedTest('nonzero_exit');
    }

    public function testSigchldHandlerIgnoresZeroExitCode(): void
    {
        $this->runIsolatedTest('zero_exit');
    }

    public function testSigchldHandlerDetectsSignalKilledChild(): void
    {
        $this->runIsolatedTest('signal_kill');
    }

    public function testSigchldHandlerSilencesSigkillOnGrpcHosts(): void
    {
        $this->runIsolatedTest('sigkill_normal_when_grpc');
    }

    public function testSigchldHandlerWarnsOnSigkillWithoutGrpc(): void
    {
        $this->runIsolatedTest('sigkill_warns_without_grpc');
    }

    public function testSigchldHandlerReapsMultipleChildren(): void
    {
        $this->runIsolatedTest('multiple_children');
    }

    public function testSchedulerWorkerUsesCorrectSignalPattern(): void
    {
        $this->runIsolatedTest('scheduler_worker_handler');
    }

    public function testSchedulerWorkerLogsExceptionsInChildProcess(): void
    {
        $this->runIsolatedTest('scheduler_worker_exception_logging');
    }

    public function testSchedulerWorkerChildSkipsPhpShutdown(): void
    {
        if (!function_exists('pcntl_exec')) {
            $this->markTestSkipped('pcntl_exec is unavailable; the task child uses the SIGKILL fallback');
        }

        $this->runIsolatedTest('scheduler_worker_child_skips_shutdown');
    }

    public function testPidFileHandleCachingIsImplemented(): void
    {
        $sourceFile = dirname(__DIR__) . '/src/Worker/SchedulerWorker.php';
        $this->assertFileExists($sourceFile);

        $content = file_get_contents($sourceFile);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            'pidFileHandles',
            $content,
            'PID file handle cache array must exist for caching (Issue #297)',
        );

        $this->assertStringContainsString(
            '$this->pidFileHandles[$pidFile]',
            $content,
            'openPidFile must check the cache before opening a new handle (Issue #297)',
        );

        $this->assertStringContainsString(
            "\$this->releaseLock(\$fp)",
            $content,
            'handleParent must call releaseLock (unlock without close) to keep handle cached (Issue #297)',
        );

        $this->assertStringContainsString(
            "\$this->releaseLock(\$fp)",
            $content,
            'handleForkError must call releaseLock (unlock without close) to keep handle cached (Issue #297)',
        );

    }

    public function testSchedulerWorkerHasMutableStateForHandleCache(): void
    {
        $sourceFile = dirname(__DIR__) . '/src/Worker/SchedulerWorker.php';
        $this->assertFileExists($sourceFile);

        $content = file_get_contents($sourceFile);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            'final class SchedulerWorker',
            $content,
            'SchedulerWorker class must not be readonly to support mutable handle cache (Issue #297)',
        );

        $this->assertStringContainsString(
            'private readonly Worker $worker;',
            $content,
            'Worker property must stay readonly even though class-level readonly was removed (Issue #297)',
        );
    }

    private function runIsolatedTest(string $testName): void
    {
        $this->assertFileExists(self::RUNNER_SCRIPT, 'Test runner script must exist');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [
                ...IsolatedPhp::command(),
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
