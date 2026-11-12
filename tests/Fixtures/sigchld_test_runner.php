<?php

declare(strict_types=1);

/** @var int<1, max> $argc */
/** @var list<string> $argv */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php sigchld_test_runner.php <test_name>\n");
    exit(2);
}

$testName = $argv[1];

require __DIR__ . '/../../vendor/autoload.php';

/** @var array<string> */
$logMessages = [];

/**
 * @param array<string> $logMessages
 */
function installSigchldHandler(array &$logMessages): void
{
    pcntl_signal(SIGCHLD, function () use (&$logMessages): void {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            if (pcntl_wifexited($status)) {
                $exitCode = pcntl_wexitstatus($status);
                if ($exitCode !== 0) {
                    $logMessages[] = sprintf('Child process %d exited with code %d', $pid, $exitCode);
                }
            } elseif (pcntl_wifsignaled($status)) {
                $signal = pcntl_wtermsig($status);
                $logMessages[] = sprintf('Child process %d was killed by signal %d', $pid, $signal);
            }
        }
    });
}

/**
 * @param array<string> $logMessages
 */
function waitForChildren(array &$logMessages, int $expectedCount, int $timeoutMs = 5000): void
{
    \CrazyGoat\WorkermanBundle\Util\Wait::until(
        function () use (&$logMessages, $expectedCount): bool {
            pcntl_signal_dispatch();

            return count($logMessages) >= $expectedCount;
        },
        (int) ($timeoutMs / 1000),
        initialDelayMs: 10,
        maxDelayMs: 10,
    );

    pcntl_signal_dispatch();
}

function waitForChildReap(int $childPid, int $timeoutMs = 5000): void
{
    \CrazyGoat\WorkermanBundle\Util\Wait::until(
        function () use ($childPid): bool {
            pcntl_signal_dispatch();
            $result = pcntl_waitpid($childPid, $status, WNOHANG);

            return $result === $childPid || $result === -1;
        },
        (int) ($timeoutMs / 1000),
        initialDelayMs: 10,
        maxDelayMs: 10,
    );
}

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

/**
 * @param array<mixed> $arr
 */
function assertCount(int $expected, array $arr, string $message): void
{
    if (count($arr) !== $expected) {
        fail("$message (expected $expected, got " . count($arr) . ": " . implode('; ', $arr) . ')');
    }
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        fail("$message (expected '$needle' in '$haystack')");
    }
}

match ($testName) {
    'nonzero_exit' => testNonZeroExit(),
    'zero_exit' => testZeroExit(),
    'signal_kill' => testSignalKill(),
    'multiple_children' => testMultipleChildren(),
    'scheduler_worker_handler' => testSchedulerWorkerHandler(),
    'scheduler_worker_exception_logging' => testSchedulerWorkerExceptionLogging(),
    'sigkill_normal_when_grpc' => testSigkillNormalWhenGrpc(),
    'sigkill_warns_without_grpc' => testSigkillWarnsWithoutGrpc(),
    'scheduler_worker_child_skips_shutdown' => testSchedulerWorkerChildSkipsShutdown(),
    default => (function () use ($testName): never {
        fwrite(STDERR, "Unknown test: $testName\n");
        exit(2);
    })(),
};

function testNonZeroExit(): void
{
    global $logMessages;
    installSigchldHandler($logMessages);

    $pid = pcntl_fork();
    if ($pid === 0) {
        exit(42);
    }
    if ($pid === -1) {
        fail('Fork failed');
    }

    waitForChildren($logMessages, 1);

    assertCount(1, $logMessages, 'Should log exactly one message for non-zero exit');
    assertContains('exited with code 42', $logMessages[0], 'Should contain exit code');
    assertContains((string) $pid, $logMessages[0], 'Should contain child PID');

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function testZeroExit(): void
{
    global $logMessages;
    installSigchldHandler($logMessages);

    $pid = pcntl_fork();
    if ($pid === 0) {
        exit(0);
    }
    if ($pid === -1) {
        fail('Fork failed');
    }

    waitForChildReap($pid);

    pcntl_signal_dispatch();

    assertCount(0, $logMessages, 'Should not log anything for exit code 0');

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function testSignalKill(): void
{
    global $logMessages;
    installSigchldHandler($logMessages);

    $pid = pcntl_fork();
    if ($pid === 0) {
        sleep(60);
        exit(0);
    }
    if ($pid === -1) {
        fail('Fork failed');
    }

    \CrazyGoat\WorkermanBundle\Util\Wait::until(static fn(): bool => \posix_kill($pid, 0), 2);

    posix_kill($pid, SIGTERM);

    waitForChildren($logMessages, 1);

    assertCount(1, $logMessages, 'Should log exactly one message for signal kill');
    assertContains('was killed by signal', $logMessages[0], 'Should indicate signal kill');
    assertContains((string) $pid, $logMessages[0], 'Should contain child PID');
    assertContains((string) SIGTERM, $logMessages[0], 'Should contain signal number');

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function testMultipleChildren(): void
{
    global $logMessages;
    installSigchldHandler($logMessages);

    $pids = [];
    for ($i = 1; $i <= 3; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            exit($i);
        }
        if ($pid === -1) {
            fail("Fork $i failed");
        }
        $pids[] = $pid;
    }

    waitForChildren($logMessages, 3);

    assertCount(3, $logMessages, 'Should log 3 messages (one per child)');

    foreach ($pids as $pid) {
        $found = false;
        foreach ($logMessages as $msg) {
            if (str_contains($msg, (string) $pid)) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            fail("PID $pid not found in log messages");
        }
    }

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function testSchedulerWorkerHandler(): void
{
    requireAutoloader();

    $logFilePath = tempnam(sys_get_temp_dir(), 'test_sigchld_');
    if ($logFilePath === false) {
        fail('Failed to create temp log file');
    }
    $logStream = fopen($logFilePath, 'w+');
    if ($logStream === false) {
        fail('Failed to open temp log stream');
    }
    \Workerman\Worker::$outputStream = $logStream;
    \Workerman\Worker::$logFile = $logFilePath;

    $schedulerWorker = createSchedulerWorkerInstance();

    $handleSigchld = (new \ReflectionMethod(
        \CrazyGoat\WorkermanBundle\Worker\SchedulerWorker::class,
        'handleSigchld',
    ))->getClosure($schedulerWorker);

    if (!$handleSigchld instanceof \Closure) {
        fail('handleSigchld must be accessible via reflection');
    }

    pcntl_signal(SIGCHLD, $handleSigchld);

    $readLogs = function () use ($logFilePath): array {
        $content = file_get_contents($logFilePath) ?: '';
        return array_values(array_filter(
            explode("\n", $content),
            fn(string $l): bool => $l !== '',
        ));
    };

    $pid1 = pcntl_fork();
    if ($pid1 === 0) {
        exit(42);
    }
    if ($pid1 === -1) {
        fail('Fork failed for non-zero exit test');
    }

    $foundNonZero = \CrazyGoat\WorkermanBundle\Util\Wait::until(
        function () use ($readLogs): bool {
            pcntl_signal_dispatch();
            foreach ($readLogs() as $log) {
                if (str_contains($log, 'exited with code 42')) {
                    return true;
                }
            }

            return false;
        },
        5,
        initialDelayMs: 10,
        maxDelayMs: 10,
    );
    if (!$foundNonZero) {
        fail('Should have logged non-zero exit (expected "exited with code 42")');
    }

    $reapResult = pcntl_waitpid(-1, $status, WNOHANG);
    if ($reapResult > 0) {
        fail(sprintf('Child should have been reaped by handler (waitpid returned %d)', $reapResult));
    }

    $logCountBefore = count($readLogs());
    $pid2 = pcntl_fork();
    if ($pid2 === 0) {
        exit(0);
    }
    if ($pid2 === -1) {
        fail('Fork failed for zero exit test');
    }

    \CrazyGoat\WorkermanBundle\Util\Wait::until(
        function () use ($pid2): bool {
            $r = pcntl_waitpid($pid2, $ws, WNOHANG);

            return $r === $pid2 || $r === -1;
        },
        5,
        initialDelayMs: 10,
        maxDelayMs: 10,
    );
    pcntl_signal_dispatch();

    $newLogs = array_slice($readLogs(), $logCountBefore);
    foreach ($newLogs as $log) {
        if (str_contains($log, 'exited with code')) {
            fail('Should not have logged any exit warning for zero exit code');
        }
    }

    $logCountBefore = count($readLogs());
    $pid3 = pcntl_fork();
    if ($pid3 === 0) {
        sleep(60);
        exit(0);
    }
    if ($pid3 === -1) {
        fail('Fork failed for signal kill test');
    }

    \CrazyGoat\WorkermanBundle\Util\Wait::until(static fn(): bool => \posix_kill($pid3, 0), 2);
    posix_kill($pid3, SIGTERM);

    $foundKill = \CrazyGoat\WorkermanBundle\Util\Wait::until(
        function () use ($readLogs, $logCountBefore): bool {
            pcntl_signal_dispatch();
            $newLogs = array_slice($readLogs(), $logCountBefore);
            foreach ($newLogs as $log) {
                if (str_contains($log, 'was killed by signal')) {
                    return true;
                }
            }

            return false;
        },
        5,
        initialDelayMs: 10,
        maxDelayMs: 10,
    );
    if (!$foundKill) {
        fail('Should have logged signal kill message (expected "was killed by signal")');
    }

    $reapResult2 = pcntl_waitpid(-1, $status, WNOHANG);
    if ($reapResult2 > 0) {
        fail(sprintf('Killed child should have been reaped by handler (waitpid returned %d)', $reapResult2));
    }

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function requireAutoloader(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        fail("Autoloader not found at $autoloadPath");
    }
    require $autoloadPath;
    $loaded = true;
}

function testSchedulerWorkerExceptionLogging(): void
{
    requireAutoloader();

    $logFilePath = tempnam(sys_get_temp_dir(), 'test_exc_log_');
    if ($logFilePath === false) {
        fail('Failed to create temp log file');
    }
    $logStream = fopen($logFilePath, 'w+');
    if ($logStream === false) {
        fail('Failed to open temp log stream');
    }
    \Workerman\Worker::$outputStream = $logStream;
    \Workerman\Worker::$logFile = $logFilePath;

    $pidDir = sys_get_temp_dir();
    \Workerman\Worker::$pidFile = $pidDir . '/workerman_test.pid';

    $schedulerWorker = createSchedulerWorkerInstance();

    $handleChild = (new \ReflectionMethod(
        \CrazyGoat\WorkermanBundle\Worker\SchedulerWorker::class,
        'handleChild',
    ))->getClosure($schedulerWorker);

    if (!$handleChild instanceof \Closure) {
        fail('handleChild must be accessible via reflection');
    }

    $service = new \CrazyGoat\WorkermanBundle\Util\ServiceMethod('test.exception.service', '__invoke');
    $taskName = 'test_exception_task';

    $container = new class implements \Psr\Container\ContainerInterface {
        public function get(string $id): mixed
        {
            throw new \RuntimeException('Simulated container error');
        }

        public function has(string $id): bool
        {
            return true;
        }
    };

    $eventDispatcher = new class implements \Symfony\Contracts\EventDispatcher\EventDispatcherInterface {
        public function dispatch(object $event, ?string $eventName = null): object
        {
            throw new \RuntimeException('Simulated event dispatch failure');
        }
    };

    $handler = new \CrazyGoat\WorkermanBundle\Scheduler\TaskHandler($container, $eventDispatcher);

    $pid = pcntl_fork();
    if ($pid === -1) {
        fail('Fork failed');
    }

    if ($pid === 0) {
        $pidFile = sprintf(
            '%s/workerman.task.%s.pid',
            dirname(\Workerman\Worker::$pidFile),
            hash('xxh64', $service->toString()),
        );
        touch($pidFile);

        try {
            $handleChild($pidFile, $service, $taskName, $handler);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'handleChild did not exit: ' . $e->getMessage() . "\n");
            exit(255);
        }
        exit(255);
    }

    $status = 0;
    $reaped = \CrazyGoat\WorkermanBundle\Util\Wait::until(
        function () use ($pid, &$status): bool {
            $result = pcntl_waitpid($pid, $status, WNOHANG);

            return $result === $pid || $result === -1;
        },
        10,
        initialDelayMs: 10,
        maxDelayMs: 10,
    );

    if (!$reaped) {
        fail('Child process did not terminate within timeout');
    }

    if (!pcntl_wifexited($status)) {
        fail('Child should have exited normally');
    }

    $exitCode = pcntl_wexitstatus($status);
    if ($exitCode !== 1) {
        fail("Child should exit with code 1 when handler throws, got $exitCode");
    }

    $logs = file_get_contents($logFilePath) ?: '';
    if (!str_contains($logs, 'failed')) {
        fail('Log must contain failure indicator');
    }
    if (!str_contains($logs, $taskName)) {
        fail('Log must contain task name');
    }

    fclose($logStream);
    @unlink($logFilePath);

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function testSchedulerWorkerChildSkipsShutdown(): void
{
    requireAutoloader();

    $logFilePath = tempnam(sys_get_temp_dir(), 'test_shutdown_log_');
    if ($logFilePath === false) {
        fail('Failed to create temp log file');
    }
    $logStream = fopen($logFilePath, 'w+');
    if ($logStream === false) {
        fail('Failed to open temp log stream');
    }
    \Workerman\Worker::$outputStream = $logStream;
    \Workerman\Worker::$logFile = $logFilePath;
    $pidDir = sys_get_temp_dir() . '/wm_shutdown_' . uniqid();
    mkdir($pidDir, 0700, true);
    \Workerman\Worker::$pidFile = $pidDir . '/workerman_test.pid';

    $marker = sys_get_temp_dir() . '/workerman_shutdown_marker_' . uniqid();
    $schedulerWorker = createSchedulerWorkerInstance();
    $handleChild = (new \ReflectionMethod(
        \CrazyGoat\WorkermanBundle\Worker\SchedulerWorker::class,
        'handleChild',
    ))->getClosure($schedulerWorker);

    if (!$handleChild instanceof \Closure) {
        fail('handleChild must be accessible via reflection');
    }

    $service = new \CrazyGoat\WorkermanBundle\Util\ServiceMethod('test.shutdown.service', '__invoke');
    $taskName = 'shutdown_task';
    $taskPidFile = sprintf(
        '%s/workerman.task.%s.pid',
        dirname(\Workerman\Worker::$pidFile),
        hash('xxh64', $service->toString()),
    );

    $container = new class ($marker) implements \Psr\Container\ContainerInterface {
        public function __construct(private readonly string $marker)
        {
        }

        public function get(string $id): mixed
        {
            $marker = $this->marker;

            return new class ($marker) {
                public function __construct(private readonly string $marker)
                {
                }

                public function __invoke(): never
                {
                    register_shutdown_function(function (): void {
                        file_put_contents($this->marker, 'ran');
                    });
                    exit(0);
                }
            };
        }

        public function has(string $id): bool
        {
            return true;
        }
    };

    $handler = new \CrazyGoat\WorkermanBundle\Scheduler\TaskHandler(
        $container,
        new \Symfony\Component\EventDispatcher\EventDispatcher(),
    );

    $pid = pcntl_fork();
    if ($pid === -1) {
        fail('Fork failed');
    }

    if ($pid === 0) {
        fclose(STDOUT);
        fclose(STDERR);

        $pidFile = sprintf(
            '%s/workerman.task.%s.pid',
            dirname(\Workerman\Worker::$pidFile),
            hash('xxh64', $service->toString()),
        );
        touch($pidFile);

        try {
            $handleChild($pidFile, $service, $taskName, $handler);
        } catch (\Throwable) {
            exit(255);
        }
        exit(255);
    }

    $status = 0;
    $reaped = \CrazyGoat\WorkermanBundle\Util\Wait::until(
        function () use ($pid, &$status): bool {
            $result = pcntl_waitpid($pid, $status, WNOHANG);

            return $result === $pid || $result === -1;
        },
        10,
        initialDelayMs: 10,
        maxDelayMs: 10,
    );

    if (!$reaped) {
        fail('Child process did not terminate within timeout');
    }

    if (!pcntl_wifexited($status)) {
        fail('Task child should exit normally through the exec hand-off');
    }

    if (pcntl_wexitstatus($status) !== 0) {
        fail('Task child should exit with code 0, got ' . pcntl_wexitstatus($status));
    }

    if (file_exists($marker)) {
        @unlink($marker);
        fail('Task child ran PHP shutdown; the libevent cleanup would kill the scheduler signal events');
    }

    if (file_exists($taskPidFile)) {
        @unlink($taskPidFile);
        fail('Task child must delete its PID file on the exit/fatal hand-off path');
    }

    fclose($logStream);
    @unlink($logFilePath);
    @unlink($taskPidFile);
    @unlink($pidDir . '/workerman_test.pid');
    @rmdir($pidDir);

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function createSchedulerWorkerInstance(): \CrazyGoat\WorkermanBundle\Worker\SchedulerWorker
{
    $schedulerWorker = (new \ReflectionClass(
        \CrazyGoat\WorkermanBundle\Worker\SchedulerWorker::class,
    ))->newInstanceWithoutConstructor();

    $mockWorker = new \Workerman\Worker();
    $mockWorker->name = '[Test]';

    $workerProp = new \ReflectionProperty(
        \CrazyGoat\WorkermanBundle\Worker\SchedulerWorker::class,
        'worker',
    );
    $workerProp->setValue($schedulerWorker, $mockWorker);

    return $schedulerWorker;
}

function testSigkillNormalWhenGrpc(): void
{
    requireAutoloader();

    \CrazyGoat\WorkermanBundle\Worker\SchedulerWorker::setSigkillNormalCompletion(true);

    $logFilePath = tempnam(sys_get_temp_dir(), 'test_sigchld_');
    if ($logFilePath === false) {
        fail('Failed to create temp log file');
    }
    $logStream = fopen($logFilePath, 'w+');
    if ($logStream === false) {
        fail('Failed to open temp log stream');
    }
    \Workerman\Worker::$logFile = $logFilePath;
    \Workerman\Worker::$outputStream = $logStream;

    $schedulerWorker = createSchedulerWorkerInstance();
    $handleSigchld = (new \ReflectionMethod(
        \CrazyGoat\WorkermanBundle\Worker\SchedulerWorker::class,
        'handleSigchld',
    ))->getClosure($schedulerWorker);

    if (!$handleSigchld instanceof \Closure) {
        fail('handleSigchld must be accessible via reflection');
    }

    pcntl_signal(SIGCHLD, $handleSigchld);

    $pid = pcntl_fork();
    if ($pid === 0) {
        \posix_kill(\posix_getpid(), \SIGKILL);
    }
    if ($pid === -1) {
        fail('Fork failed');
    }

    $reaped = \CrazyGoat\WorkermanBundle\Util\Wait::until(
        function () use ($pid): bool {
            pcntl_signal_dispatch();
            return !\posix_kill($pid, 0);
        },
        5,
        initialDelayMs: 10,
        maxDelayMs: 10,
    );
    if (!$reaped) {
        fail('SIGKILL child was not reaped by SIGCHLD handler');
    }
    usleep(50_000);
    pcntl_signal_dispatch();

    if (str_contains((string) file_get_contents($logFilePath), 'was killed by signal')) {
        fail('SIGKILL completion must not be logged as a crash on grpc hosts');
    }

    $pid2 = pcntl_fork();
    if ($pid2 === 0) {
        sleep(60);
        exit(0);
    }
    if ($pid2 === -1) {
        fail('Fork failed');
    }
    \CrazyGoat\WorkermanBundle\Util\Wait::until(static fn(): bool => \posix_kill($pid2, 0), 2);
    \posix_kill($pid2, \SIGTERM);

    $found = \CrazyGoat\WorkermanBundle\Util\Wait::until(
        function () use ($logFilePath): bool {
            pcntl_signal_dispatch();
            $log = (string) file_get_contents($logFilePath);

            return str_contains($log, 'was killed by signal');
        },
        5,
        initialDelayMs: 10,
        maxDelayMs: 10,
    );
    if (!$found) {
        fail('Non-SIGKILL signal death must still be logged on grpc hosts');
    }

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function testSigkillWarnsWithoutGrpc(): void
{
    requireAutoloader();

    $logFilePath = tempnam(sys_get_temp_dir(), 'test_sigchld_');
    if ($logFilePath === false) {
        fail('Failed to create temp log file');
    }
    $logStream = fopen($logFilePath, 'w+');
    if ($logStream === false) {
        fail('Failed to open temp log stream');
    }
    \Workerman\Worker::$logFile = $logFilePath;
    \Workerman\Worker::$outputStream = $logStream;

    $schedulerWorker = createSchedulerWorkerInstance();
    $handleSigchld = (new \ReflectionMethod(
        \CrazyGoat\WorkermanBundle\Worker\SchedulerWorker::class,
        'handleSigchld',
    ))->getClosure($schedulerWorker);

    pcntl_signal(SIGCHLD, $handleSigchld);

    $pid = pcntl_fork();
    if ($pid === 0) {
        \posix_kill(\posix_getpid(), \SIGKILL);
    }
    if ($pid === -1) {
        fail('Fork failed');
    }

    $found = \CrazyGoat\WorkermanBundle\Util\Wait::until(
        function () use ($logFilePath): bool {
            pcntl_signal_dispatch();

            return str_contains((string) file_get_contents($logFilePath), 'was killed by signal 9');
        },
        5,
        initialDelayMs: 10,
        maxDelayMs: 10,
    );
    if (!$found) {
        fail('SIGKILL death must still warn on hosts without grpc');
    }

    fwrite(STDOUT, "PASS\n");
    exit(0);
}
