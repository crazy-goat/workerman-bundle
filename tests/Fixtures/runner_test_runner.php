<?php

/**
 * Standalone test runner for Runner::warmUpCache() fork error handling.
 *
 * Runs outside the PHPUnit process to avoid inheriting output buffers,
 * shutdown functions, and other PHPUnit state that interferes with
 * pcntl_fork() + exit() behavior.
 *
 * Usage: php runner_test_runner.php <test_name>
 *
 * Exit codes:
 *   0 = test passed
 *   1 = test failed (message on stderr)
 *   2 = invalid usage
 */

declare(strict_types=1);

use CrazyGoat\WorkermanBundle\ConfigLoader;
use CrazyGoat\WorkermanBundle\Exception\CacheWarmupException;
use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Runner;
use CrazyGoat\WorkermanBundle\Test\StuckChildRunner;

/** @var int<1, max> $argc */
/** @var list<string> $argv */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php runner_test_runner.php <test_name>\n");
    exit(2);
}

$testName = $argv[1];

require __DIR__ . '/../../vendor/autoload.php';

final readonly class ExitCodeRunner extends Runner
{
    protected function fork(): int
    {
        $pid = \pcntl_fork();
        if ($pid === 0) {
            exit(3);
        }

        return $pid;
    }
}

final readonly class UnexpectedSignalRunner extends Runner
{
    protected function fork(): int
    {
        $pid = \pcntl_fork();
        if ($pid === 0) {
            // The parent process may ignore SIGUSR1; reset it so the child
            // is really killed by the signal.
            \pcntl_signal(\SIGUSR1, \SIG_DFL);
            \posix_kill((int) \getmypid(), \SIGUSR1);
            exit(0);
        }

        return $pid;
    }
}

final readonly class InvalidPidRunner extends Runner
{
    protected function fork(): int
    {
        // Never a child of this process; pcntl_waitpid() returns -1 (ECHILD).
        return \posix_getpid();
    }
}


function fail(string $message): never
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function pass(): never
{
    fwrite(STDOUT, "PASS\n");
    exit(0);
}

match ($testName) {
    'warmup_boot_failure_sigterm' => testWarmupBootFailureSigterm(),
    'warmup_exit_code_nonzero' => testWarmupExitCodeNonzero(),
    'warmup_unexpected_signal' => testWarmupUnexpectedSignal(),
    'warmup_wait_failed' => testWarmupWaitFailed(),
    'warmup_timeout_kicks_in' => testWarmupTimeoutKicksIn(),
    default => (function () use ($testName): never {
        fwrite(STDERR, "Unknown test: $testName\n");
        exit(2);
    })(),
};

/**
 * Kernel boot throws in the child, which then SIGTERMs itself. The parent
 * must throw the SIGTERM-specific message and the child message must be on
 * stderr.
 */
function testWarmupBootFailureSigterm(): void
{
    $tmpDir = sys_get_temp_dir() . '/workerman_warmup_boot_' . uniqid();
    mkdir($tmpDir, 0700, true);

    try {
        $configLoader = makeConfigLoader($tmpDir);
        $kernelFactory = new KernelFactory(
            static fn(): never => throw new \RuntimeException('Boot failed intentionally'),
            [],
        );
        $runner = new Runner($kernelFactory, 5);

        try {
            runWarmup($runner, $configLoader);
            fail('Expected CacheWarmupException for a failed kernel boot');
        } catch (CacheWarmupException $e) {
            if (!str_contains($e->getMessage(), 'child signaled failure via SIGTERM')) {
                fail('Unexpected exception message: ' . $e->getMessage());
            }
        }
    } finally {
        removeDir($tmpDir);
    }

    pass();
}

/**
 * The child exits with a non-zero code; the parent must report that code.
 */
function testWarmupExitCodeNonzero(): void
{
    $tmpDir = sys_get_temp_dir() . '/workerman_warmup_exit_' . uniqid();
    mkdir($tmpDir, 0700, true);

    try {
        $configLoader = makeConfigLoader($tmpDir);
        $runner = new ExitCodeRunner(unusedKernelFactory(), 5);

        try {
            runWarmup($runner, $configLoader);
            fail('Expected CacheWarmupException for a non-zero exit code');
        } catch (CacheWarmupException $e) {
            if (!str_contains($e->getMessage(), 'exit code 3')) {
                fail('Unexpected exception message: ' . $e->getMessage());
            }
        }
    } finally {
        removeDir($tmpDir);
    }

    pass();
}

/**
 * The child is killed by a signal other than SIGTERM or SIGKILL; the parent
 * must report the unexpected signal.
 */
function testWarmupUnexpectedSignal(): void
{
    $tmpDir = sys_get_temp_dir() . '/workerman_warmup_signal_' . uniqid();
    mkdir($tmpDir, 0700, true);

    try {
        $configLoader = makeConfigLoader($tmpDir);
        $runner = new UnexpectedSignalRunner(unusedKernelFactory(), 5);

        try {
            runWarmup($runner, $configLoader);
            fail('Expected CacheWarmupException for an unexpected signal');
        } catch (CacheWarmupException $e) {
            if (!str_contains($e->getMessage(), 'unexpected signal ' . SIGUSR1)) {
                fail('Unexpected exception message: ' . $e->getMessage());
            }
        }
    } finally {
        removeDir($tmpDir);
    }

    pass();
}

/**
 * pcntl_waitpid() fails: the PID is a real process that is not a child, so
 * the call returns -1 (ECHILD) even when other children exist.
 */
function testWarmupWaitFailed(): void
{
    $tmpDir = sys_get_temp_dir() . '/workerman_warmup_wait_' . uniqid();
    mkdir($tmpDir, 0700, true);

    try {
        $configLoader = makeConfigLoader($tmpDir);
        $runner = new InvalidPidRunner(unusedKernelFactory(), 5);

        try {
            runWarmup($runner, $configLoader);
            fail('Expected CacheWarmupException when waitpid fails');
        } catch (CacheWarmupException $e) {
            if (!str_contains($e->getMessage(), 'Failed to wait for cache warmup process')) {
                fail('Unexpected exception message: ' . $e->getMessage());
            }
        }
    } finally {
        removeDir($tmpDir);
    }

    pass();
}

/**
 * End-to-end test: Runner::warmUpCache() honors the cacheWarmupTimeout
 * constructor argument. Verifies that when the child process is stuck
 * (simulated by sleep), the parent throws RuntimeException with the
 * expected message after the configured timeout elapses.
 *
 * Uses a 1-second timeout to keep the test fast.
 */
function testWarmupTimeoutKicksIn(): void
{
    $tmpDir = sys_get_temp_dir() . '/workerman_warmup_timeout_' . uniqid();
    mkdir($tmpDir, 0700, true);

    try {
        $configLoader = makeConfigLoader($tmpDir);
        $runner = new StuckChildRunner(unusedKernelFactory(), 1);

        $start = microtime(true);

        try {
            runWarmup($runner, $configLoader);
            fail('Expected CacheWarmupException for cache warmup timeout');
        } catch (CacheWarmupException $e) {
            $elapsed = microtime(true) - $start;

            if (!str_contains($e->getMessage(), 'Cache warmup timed out after 1 seconds')) {
                fail('Unexpected exception message: ' . $e->getMessage());
            }

            // Should fire roughly at the 1s deadline. Allow generous slack
            // for slow CI but fail loudly if it took way too long.
            // Wait::until() uses microtime(true), so the timeout fires
            // shortly after the 1s deadline (about 1.0-1.25s).
            if ($elapsed > 3.0) {
                fail(sprintf('Timeout fired too late: %.2fs (expected ~1s)', $elapsed));
            }
            if ($elapsed < 0.05) {
                fail(sprintf('Timeout fired too early: %.2fs (expected ~0.1-1.1s)', $elapsed));
            }
        }
    } finally {
        removeDir($tmpDir);
    }

    pass();
}

function makeConfigLoader(string $tmpDir): ConfigLoader
{
    $configLoader = new ConfigLoader(
        projectDir: $tmpDir,
        cacheDir: $tmpDir . '/var/cache/test',
        isDebug: false,
    );

    if ($configLoader->isFresh()) {
        fail('ConfigLoader must not be fresh (cache file should not exist)');
    }

    return $configLoader;
}

function unusedKernelFactory(): KernelFactory
{
    return new KernelFactory(
        static fn(): never => throw new \RuntimeException('not used'),
        [],
    );
}

function runWarmup(Runner $runner, ConfigLoader $configLoader): void
{
    $ref = new \ReflectionMethod($runner, 'warmUpCache');
    $ref->invoke($runner, $configLoader);
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    rmdir($dir);
}
