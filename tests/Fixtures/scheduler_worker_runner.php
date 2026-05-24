<?php

declare(strict_types=1);

/**
 * Standalone SchedulerWorker behavior test runner.
 *
 * Runs outside PHPUnit process to avoid inheriting output buffers,
 * shutdown functions, and other PHPUnit state that interferes with
 * pcntl_fork() + exit() behavior.
 *
 * Uses reflection to exercise the real fork/flock/signal logic
 * of SchedulerWorker.
 *
 * Usage: php scheduler_worker_runner.php <test_name> <autoload_path> <temp_dir>
 *
 * Exit codes:
 *   0 = test passed
 *   1 = test failed (message on stderr)
 *   2 = invalid usage
 */

$testName = $argv[1] ?? '';
$autoloadPath = $argv[2] ?? '';
$tempDir = $argv[3] ?? '';

if ($testName === '' || $autoloadPath === '' || $tempDir === '' || !is_dir($tempDir)) {
    fwrite(STDERR, "Usage: php scheduler_worker_runner.php <test_name> <autoload_path> <temp_dir>\n");
    exit(2);
}

require $autoloadPath;

if (\extension_loaded('pcntl')) {
    \pcntl_async_signals(false);
}

use CrazyGoat\WorkermanBundle\Scheduler\TaskHandler;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\PeriodicalTrigger;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\TriggerInterface;
use CrazyGoat\WorkermanBundle\Worker\SchedulerWorker;
use Workerman\Worker;

$pidDir = $tempDir . '/pids';
@mkdir($pidDir, 0755, true);

Worker::$pidFile = $pidDir . '/workerman.pid';
Worker::$logFile = $tempDir . '/workerman.log';
$stream = fopen($tempDir . '/workerman_output.log', 'a+');
if ($stream === false) {
    throw new \RuntimeException('Cannot open output stream');
}
Worker::$outputStream = $stream;

function createMockHandler(): TaskHandler
{
    $dispatcher = new class implements \Symfony\Contracts\EventDispatcher\EventDispatcherInterface {
        public function dispatch(object $event, ?string $eventName = null): object
        {
            return $event;
        }
    };
    $container = new class implements \Psr\Container\ContainerInterface {
        public function get(string $id): mixed
        {
            return new class {
                public function __invoke(): void
                {
                }
            };
        }
        public function has(string $id): bool
        {
            return true;
        }
    };
    return new TaskHandler($container, $dispatcher);
}

function createSchedulerWorkerWithHandler(): SchedulerWorker
{
    $kernelFactory = new \CrazyGoat\WorkermanBundle\KernelFactory(
        fn(): \Symfony\Component\HttpKernel\KernelInterface => new class extends \Symfony\Component\HttpKernel\Kernel {
            public function __construct()
            {
            }
            /** @return array<int, \Symfony\Component\HttpKernel\Bundle\BundleInterface> */
            public function registerBundles(): array
            {
                return [];
            }
            public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
            {
            }
            public function getProjectDir(): string
            {
                return sys_get_temp_dir();
            }
            public function getCacheDir(): string
            {
                return sys_get_temp_dir() . '/cache';
            }
            public function getLogDir(): string
            {
                return sys_get_temp_dir() . '/logs';
            }
        },
        [],
    );

    $scheduler = new SchedulerWorker($kernelFactory, null, null, []);

    $handlerProp = new \ReflectionProperty(SchedulerWorker::class, 'handler');
    $handlerProp->setValue($scheduler, createMockHandler());

    return $scheduler;
}

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail("$message (expected $expected, got $actual)");
    }
}

function assertTrue(mixed $value, string $message): void
{
    if ($value !== true) {
        fail("$message (expected true)");
    }
}

function assertFalse(mixed $value, string $message): void
{
    if ($value !== false) {
        fail("$message (expected false)");
    }
}

function assertFileExists(string $path, string $message): void
{
    if (!is_file($path)) {
        fail("$message (file $path does not exist)");
    }
}

function assertFileNotExists(string $path, string $message): void
{
    if (is_file($path)) {
        fail("$message (file $path exists)");
    }
}

function getPidPath(SchedulerWorker $scheduler, string $service): string
{
    $method = new \ReflectionMethod(SchedulerWorker::class, 'getTaskPidPath');
    return $method->invoke($scheduler, $service);
}

function waitForChild(int $timeoutMs = 5000): int
{
    $deadline = microtime(true) + ($timeoutMs / 1000);
    while (microtime(true) < $deadline) {
        $pid = pcntl_wait($status, WNOHANG);
        if ($pid > 0 || $pid === -1) {
            return $pid === -1 ? -1 : $status;
        }
        usleep(10_000);
    }
    return -2;
}

$serviceId = 'test_service';
$taskName = 'test-task';
$fullService = 'test_service::__invoke';
$trigger = new PeriodicalTrigger(100);

match ($testName) {
    'fork_success' => testForkSuccess($tempDir, $pidDir, $trigger, $serviceId, $taskName, $fullService),
    'fork_error' => testForkError($tempDir, $pidDir, $trigger, $serviceId, $taskName, $fullService),
    'lock_contention' => testLockContention($tempDir, $pidDir, $trigger, $serviceId, $taskName, $fullService),
    'pid_lifecycle' => testPidLifecycle($tempDir, $pidDir, $trigger, $serviceId, $taskName, $fullService),
    default => (function () use ($testName): never {
        fwrite(STDERR, "Unknown test: $testName\n");
        exit(2);
    })(),
};

function testForkSuccess(
    string $tempDir,
    string $pidDir,
    TriggerInterface $trigger,
    string $serviceId,
    string $taskName,
    string $fullService,
): void {
    $scheduler = createSchedulerWorkerWithHandler();
    $runCallback = new \ReflectionMethod(SchedulerWorker::class, 'runCallback');
    $pidFile = getPidPath($scheduler, $fullService);

    $runCallback->invoke($scheduler, $trigger, $fullService, $taskName);

    $status = waitForChild();
    assertTrue($status >= 0, 'Child process should terminate within timeout');
    $exitCode = pcntl_wexitstatus($status);
    assertSame(0, $exitCode, 'Child should exit with code 0');

    assertFileNotExists($pidFile, 'PID file should be removed after child exit');

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function testForkError(
    string $tempDir,
    string $pidDir,
    TriggerInterface $trigger,
    string $serviceId,
    string $taskName,
    string $fullService,
): void {
    $scheduler = createSchedulerWorkerWithHandler();
    $handleForkError = new \ReflectionMethod(SchedulerWorker::class, 'handleForkError');
    $pidFile = getPidPath($scheduler, $fullService);

    $fp = fopen($pidFile, 'c');
    if ($fp === false) {
        fail('Cannot open PID file for testing');
    }

    $locked = flock($fp, LOCK_EX | LOCK_NB);
    assertTrue($locked, 'Should acquire initial lock on PID file');

    $handleForkError->invoke($scheduler, $fp, $trigger, $fullService, $taskName);

    assertFileExists($pidFile, 'PID file should still exist after handleForkError');

    $verifierFp = fopen($pidFile, 'c');
    if ($verifierFp === false) {
        fail('Cannot open PID file for verification');
    }

    $lockAvailable = flock($verifierFp, LOCK_EX | LOCK_NB);
    assertTrue(
        $lockAvailable,
        'Lock should be available after handleForkError releases and closes it',
    );
    flock($verifierFp, LOCK_UN);
    fclose($verifierFp);

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function testLockContention(
    string $tempDir,
    string $pidDir,
    TriggerInterface $trigger,
    string $serviceId,
    string $taskName,
    string $fullService,
): void {
    $scheduler = createSchedulerWorkerWithHandler();
    $runCallback = new \ReflectionMethod(SchedulerWorker::class, 'runCallback');
    $pidFile = getPidPath($scheduler, $fullService);

    $lockFp = fopen($pidFile, 'c');
    if ($lockFp === false) {
        fail('Cannot open PID file for testing');
    }
    flock($lockFp, LOCK_EX | LOCK_NB);

    $runCallback->invoke($scheduler, $trigger, $fullService, $taskName);

    $lockReleased = flock($lockFp, LOCK_EX | LOCK_NB);
    flock($lockFp, LOCK_UN);
    fclose($lockFp);

    assertTrue(
        $lockReleased,
        'Lock should be released after runCallback handles contention (handle is closed)',
    );

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function testPidLifecycle(
    string $tempDir,
    string $pidDir,
    TriggerInterface $trigger,
    string $serviceId,
    string $taskName,
    string $fullService,
): void {
    $scheduler = createSchedulerWorkerWithHandler();
    $pidFile = getPidPath($scheduler, $fullService);

    assertFileNotExists($pidFile, 'PID file should not exist before fork');

    $pid = pcntl_fork();
    if ($pid === -1) {
        fail('Fork failed');
    }

    if ($pid === 0) {
        $fp = fopen($pidFile, 'c');
        if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
            exit(1);
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, strval(posix_getpid()));
        fflush($fp);

        assertFileExists($pidFile, 'PID file should exist in child after writing');

        fclose($fp);
        exit(0);
    }

    $status = waitForChild();
    assertTrue($status >= 0, 'Child process should terminate within timeout');
    assertSame(0, pcntl_wexitstatus($status), 'Child should exit with code 0');

    assertFileExists($pidFile, 'PID file should exist after child writes it');

    $deleteTaskPid = new \ReflectionMethod(SchedulerWorker::class, 'deleteTaskPid');
    $deleteTaskPid->invoke($scheduler, $fullService);

    assertFileNotExists($pidFile, 'PID file should be removed after deleteTaskPid');

    fwrite(STDOUT, "PASS\n");
    exit(0);
}
