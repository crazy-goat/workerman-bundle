<?php

/**
 * Standalone SIGCHLD handler test runner.
 *
 * Runs outside PHPUnit process to avoid inheriting output buffers,
 * shutdown functions, and other PHPUnit state that interferes with
 * pcntl_fork() + exit() behavior.
 *
 * Usage: php sigchld_test_runner.php <test_name>
 *
 * Exit codes:
 *   0 = test passed
 *   1 = test failed (message on stderr)
 *   2 = invalid usage
 */

declare(strict_types=1);

/** @var int<1, max> $argc */
/** @var list<string> $argv */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php sigchld_test_runner.php <test_name>\n");
    exit(2);
}

$testName = $argv[1];

/** @var array<string> */
$logMessages = [];

/**
 * Install the same SIGCHLD handler pattern used in SchedulerWorker.
 *
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
 * Wait for child processes to terminate and signals to be dispatched.
 * Uses condition-based waiting: polls until expected count is reached or timeout.
 *
 * @param array<string> $logMessages
 */
function waitForChildren(array &$logMessages, int $expectedCount, int $timeoutMs = 5000): void
{
    $deadline = microtime(true) + ($timeoutMs / 1000);

    while (microtime(true) < $deadline) {
        pcntl_signal_dispatch();
        if (count($logMessages) >= $expectedCount) {
            return;
        }
        usleep(10_000); // 10ms
    }

    // Final dispatch attempt
    pcntl_signal_dispatch();
}

/**
 * Wait for a child to terminate without expecting log messages (exit code 0).
 *
 * WARNING: This function reaps the child via pcntl_waitpid(), which means
 * the SIGCHLD handler will NOT fire for this child. Only use when no log
 * messages are expected (i.e. the child exits with code 0).
 */
function waitForChildReap(int $childPid, int $timeoutMs = 5000): void
{
    $deadline = microtime(true) + ($timeoutMs / 1000);

    while (microtime(true) < $deadline) {
        pcntl_signal_dispatch();
        // Check if child is still alive
        $result = pcntl_waitpid($childPid, $status, WNOHANG);
        if ($result === $childPid || $result === -1) {
            return;
        }
        usleep(10_000);
    }
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

// --- Test implementations ---

match ($testName) {
    'nonzero_exit' => testNonZeroExit(),
    'zero_exit' => testZeroExit(),
    'signal_kill' => testSignalKill(),
    'multiple_children' => testMultipleChildren(),
    'scheduler_worker_handler' => testSchedulerWorkerHandler(),
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

    // For zero exit, we don't expect messages — but we need to wait
    // for the child to actually terminate before checking
    waitForChildReap($pid);

    // Dispatch any pending signals
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
        // Sleep until parent sends SIGTERM
        sleep(60);
        exit(0);
    }
    if ($pid === -1) {
        fail('Fork failed');
    }

    // Small delay to ensure child is running
    usleep(50_000);

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

// --- SchedulerWorker-specific behavioral tests ---
// These tests use reflection to invoke the actual SchedulerWorker::handleSigchld
// method, providing regression protection if the handler implementation changes.

function testSchedulerWorkerHandler(): void
{
    requireAutoloader();

    // Capture Worker log output via logFile + outputStream
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

    // Test 1: Non-zero exit code triggers warning log
    $pid1 = pcntl_fork();
    if ($pid1 === 0) {
        exit(42);
    }
    if ($pid1 === -1) {
        fail('Fork failed for non-zero exit test');
    }

    $deadline = microtime(true) + 5;
    $foundNonZero = false;
    while (microtime(true) < $deadline && !$foundNonZero) {
        pcntl_signal_dispatch();
        foreach ($readLogs() as $log) {
            if (str_contains($log, 'exited with code 42')) {
                $foundNonZero = true;
                break;
            }
        }
        usleep(10_000);
    }
    if (!$foundNonZero) {
        fail('Should have logged non-zero exit (expected "exited with code 42")');
    }

    // Verify handler reaped the child
    $reapResult = pcntl_waitpid(-1, $status, WNOHANG);
    if ($reapResult > 0) {
        fail(sprintf('Child should have been reaped by handler (waitpid returned %d)', $reapResult));
    }

    // Test 2: Zero exit code produces no warning log
    $logCountBefore = count($readLogs());
    $pid2 = pcntl_fork();
    if ($pid2 === 0) {
        exit(0);
    }
    if ($pid2 === -1) {
        fail('Fork failed for zero exit test');
    }

    $deadline = microtime(true) + 5;
    $foundZeroExit = false;
    while (microtime(true) < $deadline && !$foundZeroExit) {
        $r = pcntl_waitpid($pid2, $ws, WNOHANG);
        if ($r === $pid2 || $r === -1) {
            $foundZeroExit = true;
        }
        usleep(10_000);
    }
    pcntl_signal_dispatch();

    // Check that no warning log was produced for zero exit
    $newLogs = array_slice($readLogs(), $logCountBefore);
    foreach ($newLogs as $log) {
        if (str_contains($log, 'exited with code')) {
            fail('Should not have logged any exit warning for zero exit code');
        }
    }

    // Test 3: Signal-killed child triggers warning log
    $logCountBefore = count($readLogs());
    $pid3 = pcntl_fork();
    if ($pid3 === 0) {
        sleep(60);
        exit(0);
    }
    if ($pid3 === -1) {
        fail('Fork failed for signal kill test');
    }

    usleep(50_000);
    posix_kill($pid3, SIGTERM);

    $deadline = microtime(true) + 5;
    $foundKill = false;
    while (microtime(true) < $deadline && !$foundKill) {
        pcntl_signal_dispatch();
        $newLogs = array_slice($readLogs(), $logCountBefore);
        foreach ($newLogs as $log) {
            if (str_contains($log, 'was killed by signal')) {
                $foundKill = true;
                break;
            }
        }
        usleep(10_000);
    }
    if (!$foundKill) {
        fail('Should have logged signal kill message (expected "was killed by signal")');
    }

    // Verify handler reaped the killed child
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
