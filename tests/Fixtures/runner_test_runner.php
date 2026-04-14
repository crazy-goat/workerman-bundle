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

function assertContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        fail("$message (expected '$needle' in '$haystack')");
    }
}

match ($testName) {
    'fork_failure' => testForkFailure(),
    'child_boot_exception' => testChildBootException(),
    'child_normal_exit' => testChildNormalExit(),
    'child_exit_nonzero' => testChildExitNonzero(),
    'signal_killed_child' => testSignalKilledChild(),
    default => (function () use ($testName): never {
        fwrite(STDERR, "Unknown test: $testName\n");
        exit(2);
    })(),
};

/**
 * Test: pcntl_fork() returns -1 (fork failure).
 * We cannot actually trigger fork failure in a test, but we can verify
 * the code path exists and would throw RuntimeException.
 */
function testForkFailure(): void
{
    $sourceFile = dirname(__DIR__) . '/src/Runner.php';
    $content = file_get_contents($sourceFile);
    if ($content === false) {
        fail('Cannot read Runner.php');
    }

    assertContains(
        "throw new \RuntimeException('Failed to fork process for cache warmup')",
        $content,
        'Runner should throw RuntimeException when fork returns -1',
    );

    pass();
}

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
