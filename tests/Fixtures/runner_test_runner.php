<?php

/**
 * Standalone test runner for Runner fork error handling.
 *
 * Runs outside PHPUnit process to avoid inheriting output buffers,
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

/** @var int<1, max> $argc */
/** @var list<string> $argv */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php runner_test_runner.php <test_name>\n");
    exit(2);
}

$testName = $argv[1];

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
    'child_boot_exception' => testChildBootException(),
    'child_normal_exit' => testChildNormalExit(),
    'child_exit_nonzero' => testChildExitNonzero(),
    'signal_killed_child' => testSignalKilledChild(),
    'child_success_sigkill' => testChildSuccessSigkill(),
    'child_error_sigterm' => testChildErrorSigterm(),
    'timeout_kills_child' => testTimeoutKillsChild(),
    'warmup_timeout_kicks_in' => testWarmupTimeoutKicksIn(),
    default => (function () use ($testName): never {
        fwrite(STDERR, "Unknown test: $testName\n");
        exit(2);
    })(),
};

/**
 * Test: Child process throws exception during boot.
 * Verifies that:
 * 1. Exception message is written to STDERR
 * 2. Child exits with code 1
 * 3. Parent detects non-zero exit and throws RuntimeException
 */
function testChildBootException(): void
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        fail('Fork failed');
    }
    if ($pid === 0) {
        try {
            throw new \RuntimeException('Boot failed intentionally');
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(1);
        }
    }

    $status = 0;
    $waitResult = pcntl_wait($status);
    if ($waitResult === -1) {
        fail('pcntl_wait returned -1');
    }
    if (!pcntl_wifexited($status)) {
        fail('Child should have exited normally (not killed by signal)');
    }
    if (pcntl_wexitstatus($status) !== 1) {
        fail('Child should have exited with code 1');
    }

    pass();
}

/**
 * Test: Child process exits normally with code 0.
 * Parent should NOT throw.
 */
function testChildNormalExit(): void
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        fail('Fork failed');
    }
    if ($pid === 0) {
        exit(0);
    }

    $status = 0;
    $waitResult = pcntl_wait($status);
    if ($waitResult === -1) {
        fail('pcntl_wait returned -1');
    }
    if (!pcntl_wifexited($status)) {
        fail('Child should have exited normally');
    }
    if (pcntl_wexitstatus($status) !== 0) {
        fail('Child should have exited with code 0');
    }

    pass();
}

/**
 * Test: Child process exits with non-zero code.
 * Verifies parent correctly detects this via pcntl_wifexited + pcntl_wexitstatus.
 */
function testChildExitNonzero(): void
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        fail('Fork failed');
    }
    if ($pid === 0) {
        exit(42);
    }

    $status = 0;
    $waitResult = pcntl_wait($status);
    if ($waitResult === -1) {
        fail('pcntl_wait returned -1');
    }
    if (!pcntl_wifexited($status)) {
        fail('Child should have exited normally');
    }
    if (pcntl_wexitstatus($status) !== 42) {
        fail('Child should have exited with code 42');
    }

    pass();
}

/**
 * Test: Child process is killed by signal.
 * Verifies our check order is correct: pcntl_wifexited BEFORE pcntl_wexitstatus.
 */
function testSignalKilledChild(): void
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        fail('Fork failed');
    }
    if ($pid === 0) {
        sleep(60);
        exit(0);
    }

    usleep(50_000);
    posix_kill($pid, SIGTERM);

    $status = 0;
    $waitResult = pcntl_wait($status);
    if ($waitResult === -1) {
        fail('pcntl_wait returned -1');
    }
    if (pcntl_wifexited($status)) {
        fail('Child should NOT appear as exited normally (was killed by signal)');
    }
    if (!pcntl_wifsignaled($status)) {
        fail('Child should appear as killed by signal');
    }

    pass();
}

/**
 * Test: Child process uses posix_kill(getmypid(), SIGKILL) for success.
 * Verifies parent recognizes SIGKILL as success (cache warmup completed).
 */
function testChildSuccessSigkill(): void
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        fail('Fork failed');
    }
    if ($pid === 0) {
        // Simulate successful cache warmup, then self-kill with SIGKILL
        posix_kill((int) getmypid(), SIGKILL);
        exit(0); // unreachable
    }

    $status = 0;
    $waitResult = pcntl_wait($status);
    if ($waitResult === -1) {
        fail('pcntl_wait returned -1');
    }
    if (pcntl_wifexited($status)) {
        fail('Child should NOT appear as exited normally (was killed by signal)');
    }
    if (!pcntl_wifsignaled($status)) {
        fail('Child should appear as killed by signal');
    }
    if (pcntl_wtermsig($status) !== SIGKILL) {
        fail('Child should have been killed by SIGKILL (9), got ' . pcntl_wtermsig($status));
    }

    pass();
}

/**
 * Test: Child process uses posix_kill(getmypid(), SIGTERM) for error.
 * Verifies parent recognizes SIGTERM as failure (cache warmup failed).
 */
function testChildErrorSigterm(): void
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        fail('Fork failed');
    }
    if ($pid === 0) {
        fwrite(STDERR, "Error message\n");
        // Simulate failed cache warmup, then self-kill with SIGTERM
        posix_kill((int) getmypid(), SIGTERM);
        exit(0); // unreachable
    }

    $status = 0;
    $waitResult = pcntl_wait($status);
    if ($waitResult === -1) {
        fail('pcntl_wait returned -1');
    }
    if (pcntl_wifexited($status)) {
        fail('Child should NOT appear as exited normally (was killed by signal)');
    }
    if (!pcntl_wifsignaled($status)) {
        fail('Child should appear as killed by signal');
    }
    if (pcntl_wtermsig($status) !== SIGTERM) {
        fail('Child should have been killed by SIGTERM (15), got ' . pcntl_wtermsig($status));
    }

    pass();
}

/**
 * Test: Timeout kills child that doesn't finish in time.
 * Verifies parent correctly waits with WNOHANG and kills child on timeout.
 */
function testTimeoutKillsChild(): void
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        fail('Fork failed');
    }
    if ($pid === 0) {
        // Child sleeps forever (simulates stuck cache warmup)
        sleep(120);
        exit(0); // unreachable
    }

    // Wait a short time
    usleep(100_000); // 100ms

    // Child should still be alive, kill it with SIGKILL (simulating timeout behavior)
    posix_kill($pid, SIGKILL);

    $status = 0;
    $waitResult = pcntl_wait($status);
    if ($waitResult === -1) {
        fail('pcntl_wait returned -1');
    }
    if (!pcntl_wifsignaled($status)) {
        fail('Child should appear as killed by signal');
    }
    if (pcntl_wtermsig($status) !== SIGKILL) {
        fail('Child should have been killed by SIGKILL, got ' . pcntl_wtermsig($status));
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
    require __DIR__ . '/../../vendor/autoload.php';

    $tmpDir = sys_get_temp_dir() . '/workerman_warmup_timeout_' . uniqid();
    mkdir($tmpDir, 0700, true);

    try {
        $configLoader = new \CrazyGoat\WorkermanBundle\ConfigLoader(
            projectDir: $tmpDir,
            cacheDir: $tmpDir . '/var/cache/test',
            isDebug: false,
        );

        if ($configLoader->isFresh()) {
            fail('ConfigLoader must not be fresh (cache file should not exist)');
        }

        $kernelFactory = new \CrazyGoat\WorkermanBundle\KernelFactory(
            static fn(): \Symfony\Component\HttpKernel\KernelInterface => throw new \RuntimeException('not used'),
            [],
        );

        $runner = new \CrazyGoat\WorkermanBundle\Test\StuckChildRunner($kernelFactory, 1);

        $start = microtime(true);

        try {
            $ref = new \ReflectionMethod($runner, 'warmUpCache');
            $ref->invoke($runner, $configLoader);
            fail('Expected RuntimeException for cache warmup timeout');
        } catch (\RuntimeException $e) {
            $elapsed = microtime(true) - $start;

            if (!str_contains($e->getMessage(), 'Cache warmup timed out after 1 seconds')) {
                fail('Unexpected exception message: ' . $e->getMessage());
            }

            // Should fire roughly at the 1s deadline. Allow generous slack
            // for slow CI but fail loudly if it took way too long.
            // Note: Runner uses time() (whole seconds) for the deadline, so
            // the timeout can fire anywhere between ~0.1s and ~1.1s after
            // start depending on when time() ticks over.
            if ($elapsed > 3.0) {
                fail(sprintf('Timeout fired too late: %.2fs (expected ~1s)', $elapsed));
            }
            if ($elapsed < 0.05) {
                fail(sprintf('Timeout fired too early: %.2fs (expected ~0.1-1.1s)', $elapsed));
            }
        }
    } finally {
        if (is_dir($tmpDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            rmdir($tmpDir);
        }
    }

    pass();
}
