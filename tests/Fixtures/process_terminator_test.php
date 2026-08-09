<?php

/**
 * Standalone test runner for ProcessTerminator.
 *
 * Runs outside the PHPUnit process (via `php -n` + posix extension) to avoid
 * inheriting the grpc extension: its shutdown handler deadlocks in forked
 * children, which is exactly the behavior under test.
 *
 * Usage: php process_terminator_test.php <hard|soft> <code>
 *
 * Exit codes:
 *   0 = test passed
 *   1 = test failed (message on stderr)
 *   2 = invalid usage
 */

declare(strict_types=1);

/** @var int<1, max> $argc */
/** @var list<string> $argv */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php process_terminator_test.php <hard|soft> <code>\n");
    exit(2);
}

require __DIR__ . '/../../vendor/autoload.php';

$mode = $argv[1];
$code = (int) $argv[2];

$pid = \pcntl_fork();
if ($pid === -1) {
    fwrite(STDERR, "FAIL: fork failed\n");
    exit(1);
}

if ($pid === 0) {
    \CrazyGoat\WorkermanBundle\Util\ProcessTerminator::terminate($code, $mode === 'hard');
}

\pcntl_waitpid($pid, $status);
if ($mode === 'hard') {
    if (!\pcntl_wifsignaled($status)) {
        fwrite(STDERR, "FAIL: expected signal death, got normal exit\n");
        exit(1);
    }
    if (\pcntl_wtermsig($status) !== \SIGKILL) {
        fwrite(STDERR, 'FAIL: expected SIGKILL, got signal ' . \pcntl_wtermsig($status) . "\n");
        exit(1);
    }
} else {
    if (!\pcntl_wifexited($status)) {
        fwrite(STDERR, "FAIL: expected normal exit, got signal death\n");
        exit(1);
    }
    if (\pcntl_wexitstatus($status) !== $code) {
        fwrite(STDERR, 'FAIL: expected exit code ' . $code . ', got ' . \pcntl_wexitstatus($status) . "\n");
        exit(1);
    }
}

fwrite(STDOUT, "PASS\n");
exit(0);
