<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\ConfigLoader;
use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Runner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

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
     * @return array{workers: array, pidMap: array, globalEvent: mixed, outputStream: mixed, logFile: mixed, defaultMaxPackageSize: int}
     */
    private function saveWorkerState(): array
    {
        $workersRef = new \ReflectionProperty(Worker::class, 'workers');
        $pidMapRef = new \ReflectionProperty(Worker::class, 'pidMap');

        return [
            'workers' => $workersRef->getValue(),
            'pidMap' => $pidMapRef->getValue(),
            'globalEvent' => Worker::$globalEvent,
            'outputStream' => Worker::$outputStream,
            'logFile' => Worker::$logFile,
            'defaultMaxPackageSize' => TcpConnection::$defaultMaxPackageSize,
        ];
    }

    private function restoreWorkerState(array $state): void
    {
        $workersRef = new \ReflectionProperty(Worker::class, 'workers');
        $workersRef->setValue(null, $state['workers']);

        $pidMapRef = new \ReflectionProperty(Worker::class, 'pidMap');
        $pidMapRef->setValue(null, $state['pidMap']);

        Worker::$globalEvent = $state['globalEvent'];
        Worker::$outputStream = $state['outputStream'];
        Worker::$logFile = $state['logFile'];
        TcpConnection::$defaultMaxPackageSize = $state['defaultMaxPackageSize'];
    }

    private function invokeRunnerMethod(Runner $runner, string $methodName, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod(Runner::class, $methodName);

        return $ref->invoke($runner, ...$args);
    }

    public function testGetCacheWarmupTimeoutDefaultsTo30Seconds(): void
    {
        $savedEnv = $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] ?? null;
        unset($_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT']);

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $timeout = $this->invokeRunnerMethod($runner, 'getCacheWarmupTimeout');

            $this->assertSame(30, $timeout);
        } finally {
            if ($savedEnv !== null) {
                $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = $savedEnv;
            } else {
                unset($_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT']);
            }
        }
    }

    public function testGetCacheWarmupTimeoutWithEnvVarOverride(): void
    {
        $savedEnv = $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] ?? null;
        $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = '15';

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $timeout = $this->invokeRunnerMethod($runner, 'getCacheWarmupTimeout');

            $this->assertSame(15, $timeout);
        } finally {
            if ($savedEnv !== null) {
                $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = $savedEnv;
            } else {
                unset($_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT']);
            }
        }
    }

    public function testGetCacheWarmupTimeoutWithEmptyEnvVarFallsBackToDefault(): void
    {
        $savedEnv = $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] ?? null;
        $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = '';

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $timeout = $this->invokeRunnerMethod($runner, 'getCacheWarmupTimeout');

            $this->assertSame(30, $timeout);
        } finally {
            if ($savedEnv !== null) {
                $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = $savedEnv;
            } else {
                unset($_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT']);
            }
        }
    }

    public function testGetCacheWarmupTimeoutRejectsNonNumericValue(): void
    {
        $savedEnv = $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] ?? null;
        $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = 'not-a-number';

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('WORKERMAN_CACHE_WARMUP_TIMEOUT must be a positive integer');
            $this->invokeRunnerMethod($runner, 'getCacheWarmupTimeout');
        } finally {
            if ($savedEnv !== null) {
                $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = $savedEnv;
            } else {
                unset($_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT']);
            }
        }
    }

    public function testGetCacheWarmupTimeoutRejectsZero(): void
    {
        $savedEnv = $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] ?? null;
        $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = '0';

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('WORKERMAN_CACHE_WARMUP_TIMEOUT must be a positive integer');
            $this->invokeRunnerMethod($runner, 'getCacheWarmupTimeout');
        } finally {
            if ($savedEnv !== null) {
                $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = $savedEnv;
            } else {
                unset($_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT']);
            }
        }
    }

    public function testGetCacheWarmupTimeoutRejectsNegative(): void
    {
        $savedEnv = $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] ?? null;
        $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = '-5';

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('WORKERMAN_CACHE_WARMUP_TIMEOUT must be a positive integer');
            $this->invokeRunnerMethod($runner, 'getCacheWarmupTimeout');
        } finally {
            if ($savedEnv !== null) {
                $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = $savedEnv;
            } else {
                unset($_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT']);
            }
        }
    }

    public function testApplyWorkermanConfigSetsWorkerStaticProperties(): void
    {
        $saved = $this->saveWorkerState();
        $tmpDir = sys_get_temp_dir() . '/workerman_runner_test_' . uniqid();
        mkdir($tmpDir, 0700, true);

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $config = [
                'pid_file' => $tmpDir . '/var/run/workerman.pid',
                'log_file' => $tmpDir . '/var/log/workerman.log',
                'stdout_file' => $tmpDir . '/var/log/workerman.stdout.log',
                'stop_timeout' => 5,
                'max_package_size' => 2048,
            ];

            $this->invokeRunnerMethod($runner, 'applyWorkermanConfig', $config);

            $this->assertSame($config['pid_file'], Worker::$pidFile);
            $this->assertSame($config['log_file'], Worker::$logFile);
            $this->assertSame($config['stdout_file'], Worker::$stdoutFile);
            $this->assertSame($config['stop_timeout'], Worker::$stopTimeout);
            $this->assertSame($config['max_package_size'], TcpConnection::$defaultMaxPackageSize);
            $this->assertTrue(is_dir($tmpDir . '/var/run'));
            $this->assertTrue(is_dir($tmpDir . '/var/log'));
        } finally {
            $this->restoreWorkerState($saved);
            $this->removeDir($tmpDir);
        }
    }

    public function testApplyWorkermanConfigSetsStatusFileFromPidFile(): void
    {
        $saved = $this->saveWorkerState();
        $tmpDir = sys_get_temp_dir() . '/workerman_runner_test_' . uniqid();
        mkdir($tmpDir, 0700, true);

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $config = [
                'pid_file' => $tmpDir . '/var/run/workerman.pid',
                'log_file' => $tmpDir . '/var/log/workerman.log',
                'stdout_file' => $tmpDir . '/var/log/workerman.stdout.log',
                'stop_timeout' => 2,
                'max_package_size' => 10 * 1024 * 1024,
            ];

            $this->invokeRunnerMethod($runner, 'applyWorkermanConfig', $config);

            $this->assertSame($tmpDir . '/var/run/workerman.status', Worker::$statusFile);
        } finally {
            $this->restoreWorkerState($saved);
            $this->removeDir($tmpDir);
        }
    }

    public function testApplyWorkermanConfigSetsOnMasterReloadToClearOpcache(): void
    {
        $saved = $this->saveWorkerState();
        $tmpDir = sys_get_temp_dir() . '/workerman_runner_test_' . uniqid();
        mkdir($tmpDir, 0700, true);

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $config = [
                'pid_file' => $tmpDir . '/var/run/workerman.pid',
                'log_file' => $tmpDir . '/var/log/workerman.log',
                'stdout_file' => $tmpDir . '/var/log/workerman.stdout.log',
                'stop_timeout' => 2,
                'max_package_size' => 10 * 1024 * 1024,
            ];

            $this->invokeRunnerMethod($runner, 'applyWorkermanConfig', $config);

            $this->assertNotNull(Worker::$onMasterReload);
            $this->assertInstanceOf(\Closure::class, Worker::$onMasterReload);
        } finally {
            $this->restoreWorkerState($saved);
            $this->removeDir($tmpDir);
        }
    }

    public function testApplyWorkermanConfigThrowsOnMkdirFailure(): void
    {
        $saved = $this->saveWorkerState();
        $tmpDir = sys_get_temp_dir() . '/workerman_runner_test_' . uniqid();
        mkdir($tmpDir, 0700, true);
        touch($tmpDir . '/var');

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $config = [
                'pid_file' => $tmpDir . '/var/run/workerman.pid',
                'log_file' => $tmpDir . '/var/log/workerman.log',
                'stdout_file' => $tmpDir . '/var/log/workerman.stdout.log',
                'stop_timeout' => 2,
                'max_package_size' => 10 * 1024 * 1024,
            ];

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unable to create directory');
            $this->invokeRunnerMethod($runner, 'applyWorkermanConfig', $config);
        } finally {
            $this->restoreWorkerState($saved);
            $this->removeDir($tmpDir);
        }
    }

    public function testCreateWorkersWithMultipleServersCreatesServerWorkers(): void
    {
        $saved = $this->saveWorkerState();

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernel->method('isDebug')->willReturn(true);
            $kernel->method('getProjectDir')->willReturn('/tmp');
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $config = [
                'servers' => [
                    ['name' => 'api', 'listen' => 'http://0.0.0.0:8080'],
                    ['name' => 'admin', 'listen' => 'http://0.0.0.0:8081'],
                ],
                'user' => null,
                'group' => null,
                'reload_strategy' => [
                    'file_monitor' => ['active' => false, 'source_dir' => [], 'file_pattern' => []],
                ],
            ];

            $beforeCount = count($this->getWorkersProperty());
            $this->invokeRunnerMethod($runner, 'createWorkers', $config, [], []);
            $afterCount = count($this->getWorkersProperty());

            $this->assertSame($beforeCount + 2, $afterCount, 'Should create exactly 2 ServerWorkers (one per server config)');
        } finally {
            $this->restoreWorkerState($saved);
        }
    }

    public function testCreateWorkersWithSchedulerCreatesSchedulerWorker(): void
    {
        $saved = $this->saveWorkerState();

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernel->method('isDebug')->willReturn(true);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $config = [
                'servers' => [],
                'user' => null,
                'group' => null,
                'reload_strategy' => [
                    'file_monitor' => ['active' => false, 'source_dir' => [], 'file_pattern' => []],
                ],
            ];
            $schedulerConfig = ['task1' => ['schedule' => '* * * * *', 'name' => 'test-task']];

            $beforeCount = count($this->getWorkersProperty());
            $this->invokeRunnerMethod($runner, 'createWorkers', $config, $schedulerConfig, []);
            $afterCount = count($this->getWorkersProperty());

            $this->assertSame($beforeCount + 1, $afterCount, 'Should create SchedulerWorker when scheduler config is non-empty');
        } finally {
            $this->restoreWorkerState($saved);
        }
    }

    public function testCreateWorkersWithoutSchedulerDoesNotCreateSchedulerWorker(): void
    {
        $saved = $this->saveWorkerState();

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernel->method('isDebug')->willReturn(true);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $config = [
                'servers' => [],
                'user' => null,
                'group' => null,
                'reload_strategy' => [
                    'file_monitor' => ['active' => false, 'source_dir' => [], 'file_pattern' => []],
                ],
            ];

            $beforeCount = count($this->getWorkersProperty());
            $this->invokeRunnerMethod($runner, 'createWorkers', $config, [], []);
            $afterCount = count($this->getWorkersProperty());

            $this->assertSame($beforeCount, $afterCount, 'Should not create SchedulerWorker when scheduler config is empty');
        } finally {
            $this->restoreWorkerState($saved);
        }
    }

    public function testCreateWorkersWithSupervisorCreatesSupervisorWorker(): void
    {
        $saved = $this->saveWorkerState();

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernel->method('isDebug')->willReturn(true);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $config = [
                'servers' => [],
                'user' => null,
                'group' => null,
                'reload_strategy' => [
                    'file_monitor' => ['active' => false, 'source_dir' => [], 'file_pattern' => []],
                ],
            ];
            $processConfig = ['process1' => ['processes' => 1]];

            $beforeCount = count($this->getWorkersProperty());
            $this->invokeRunnerMethod($runner, 'createWorkers', $config, [], $processConfig);
            $afterCount = count($this->getWorkersProperty());

            $this->assertSame($beforeCount + 1, $afterCount, 'Should create SupervisorWorker when process config is non-empty');
        } finally {
            $this->restoreWorkerState($saved);
        }
    }

    public function testCreateWorkersWithoutSupervisorDoesNotCreateSupervisorWorker(): void
    {
        $saved = $this->saveWorkerState();

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernel->method('isDebug')->willReturn(true);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $config = [
                'servers' => [],
                'user' => null,
                'group' => null,
                'reload_strategy' => [
                    'file_monitor' => ['active' => false, 'source_dir' => [], 'file_pattern' => []],
                ],
            ];

            $beforeCount = count($this->getWorkersProperty());
            $this->invokeRunnerMethod($runner, 'createWorkers', $config, [], []);
            $afterCount = count($this->getWorkersProperty());

            $this->assertSame($beforeCount, $afterCount, 'Should not create SupervisorWorker when process config is empty');
        } finally {
            $this->restoreWorkerState($saved);
        }
    }

    public function testCreateWorkersWithFileMonitorCreatesFileMonitorWorker(): void
    {
        $saved = $this->saveWorkerState();

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernel->method('isDebug')->willReturn(true);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $config = [
                'servers' => [],
                'user' => null,
                'group' => null,
                'reload_strategy' => [
                    'file_monitor' => [
                        'active' => true,
                        'source_dir' => ['/src'],
                        'file_pattern' => ['*.php'],
                    ],
                ],
            ];

            $beforeCount = count($this->getWorkersProperty());
            $this->invokeRunnerMethod($runner, 'createWorkers', $config, [], []);
            $afterCount = count($this->getWorkersProperty());

            $this->assertSame($beforeCount + 1, $afterCount, 'Should create FileMonitorWorker when file_monitor is active');
        } finally {
            $this->restoreWorkerState($saved);
        }
    }

    public function testCreateWorkersWithFileMonitorInactiveDoesNotCreateFileMonitorWorker(): void
    {
        $saved = $this->saveWorkerState();

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernel->method('isDebug')->willReturn(true);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $config = [
                'servers' => [],
                'user' => null,
                'group' => null,
                'reload_strategy' => [
                    'file_monitor' => [
                        'active' => false,
                        'source_dir' => ['/src'],
                        'file_pattern' => ['*.php'],
                    ],
                ],
            ];

            $beforeCount = count($this->getWorkersProperty());
            $this->invokeRunnerMethod($runner, 'createWorkers', $config, [], []);
            $afterCount = count($this->getWorkersProperty());

            $this->assertSame($beforeCount, $afterCount, 'Should not create FileMonitorWorker when file_monitor is inactive');
        } finally {
            $this->restoreWorkerState($saved);
        }
    }

    public function testCreateWorkersWithAllConfigsCreatesCorrectWorkerCount(): void
    {
        $saved = $this->saveWorkerState();

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernel->method('isDebug')->willReturn(true);
            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $config = [
                'servers' => [
                    ['name' => 'api', 'listen' => 'http://0.0.0.0:8080'],
                    ['name' => 'admin', 'listen' => 'http://0.0.0.0:8081'],
                ],
                'user' => null,
                'group' => null,
                'reload_strategy' => [
                    'file_monitor' => [
                        'active' => true,
                        'source_dir' => ['/src'],
                        'file_pattern' => ['*.php'],
                    ],
                ],
            ];
            $schedulerConfig = ['task1' => ['schedule' => '* * * * *', 'name' => 'test']];
            $processConfig = ['process1' => ['processes' => 1]];

            $beforeCount = count($this->getWorkersProperty());
            $this->invokeRunnerMethod($runner, 'createWorkers', $config, $schedulerConfig, $processConfig);
            $afterCount = count($this->getWorkersProperty());

            $this->assertSame($beforeCount + 5, $afterCount, 'Should create 2 servers + 1 scheduler + 1 file monitor + 1 supervisor = 5 new Worker instances');
        } finally {
            $this->restoreWorkerState($saved);
        }
    }

    public function testCreateWorkersStructuralPharGuardExists(): void
    {
        $sourceFile = self::RUNNER_SOURCE;
        $content = file_get_contents($sourceFile);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            '$this->kernelFactory->isPhar()',
            $content,
            'Must check isPhar() before deciding to create FileMonitorWorker',
        );

        $this->assertStringContainsString(
            'File monitor is disabled in PHAR mode',
            $content,
            'Must log a message when file monitor is skipped due to PHAR mode',
        );
    }

    public function testCreateConfigLoader(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn('/tmp/project');
        $kernel->method('getCacheDir')->willReturn('/tmp/project/var/cache/test');
        $kernel->method('getEnvironment')->willReturn('test');
        $kernel->method('isDebug')->willReturn(false);

        $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
        $runner = new Runner($kernelFactory);

        $loader = $this->invokeRunnerMethod($runner, 'createConfigLoader');

        $this->assertInstanceOf(ConfigLoader::class, $loader);
    }

    public function testGetCacheDirUsesDefaultFromKernel(): void
    {
        $savedEnv = $_SERVER['APP_CACHE_DIR'] ?? null;
        unset($_SERVER['APP_CACHE_DIR']);

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernel->method('getProjectDir')->willReturn('/tmp/project');
            $kernel->method('getCacheDir')->willReturn('/tmp/project/var/cache');
            $kernel->method('getEnvironment')->willReturn('test');
            $kernel->method('isDebug')->willReturn(false);

            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $cacheDir = $this->invokeRunnerMethod($runner, 'getCacheDir');

            $this->assertSame('/tmp/project/var/cache/test', $cacheDir);
        } finally {
            if ($savedEnv !== null) {
                $_SERVER['APP_CACHE_DIR'] = $savedEnv;
            } else {
                unset($_SERVER['APP_CACHE_DIR']);
            }
        }
    }

    public function testGetCacheDirUsesEnvOverride(): void
    {
        $savedEnv = $_SERVER['APP_CACHE_DIR'] ?? null;
        $_SERVER['APP_CACHE_DIR'] = '/custom/cache';

        try {
            $kernel = $this->createMock(KernelInterface::class);
            $kernel->method('getProjectDir')->willReturn('/tmp/project');
            $kernel->method('getCacheDir')->willReturn('/tmp/project/var/cache');
            $kernel->method('getEnvironment')->willReturn('prod');

            $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);
            $runner = new Runner($kernelFactory);

            $cacheDir = $this->invokeRunnerMethod($runner, 'getCacheDir');

            $this->assertSame('/custom/cache/prod', $cacheDir);
        } finally {
            if ($savedEnv !== null) {
                $_SERVER['APP_CACHE_DIR'] = $savedEnv;
            } else {
                unset($_SERVER['APP_CACHE_DIR']);
            }
        }
    }

    public function testResolveRuntimePathStructuralPharRewritingExists(): void
    {
        $sourceFile = self::RUNNER_SOURCE;
        $content = file_get_contents($sourceFile);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            'PharHelper::resolveRuntimePath',
            $content,
            'Must delegate runtime path resolution to PharHelper for PHAR-friendly path rewriting',
        );
    }

    /**
     * @return array<string, Worker>
     */
    private function getWorkersProperty(): array
    {
        $ref = new \ReflectionProperty(Worker::class, 'workers');

        return $ref->getValue();
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($path);
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
