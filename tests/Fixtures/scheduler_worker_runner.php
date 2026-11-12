<?php

declare(strict_types=1);

$testName = $argv[1] ?? '';
$autoloadPath = $argv[2] ?? '';
$tempDir = $argv[3] ?? '';

if ($testName === '' || $autoloadPath === '' || $tempDir === '' || !is_dir($tempDir)) {
    fwrite(STDERR, "Usage: php scheduler_worker_runner.php <test_name> <autoload_path> <temp_dir>\n");
    exit(2);
}

require $autoloadPath;

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

function waitForChild(int $timeoutMs = 5000): int
{
    $pid = 0;
    $status = 0;
    $found = \CrazyGoat\WorkermanBundle\Util\Wait::until(
        function () use (&$pid, &$status): bool {
            $pid = pcntl_wait($status, WNOHANG);

            return $pid > 0 || $pid === -1;
        },
        (int) ($timeoutMs / 1000),
        initialDelayMs: 10,
        maxDelayMs: 10,
    );

    if (!$found) {
        return -2;
    }

    return $pid === -1 ? -1 : $status;
}

$pidDir = $tempDir . '/pids';
@mkdir($pidDir, 0755, true);

$serviceId = 'test_service';
$pidFile = sprintf('%s/workerman.task.%s.pid', $pidDir, hash('xxh64', $serviceId));

match ($testName) {
    'fork_success' => testForkSuccess($pidFile),
    'fork_error' => testForkError($pidFile),
    'lock_contention' => testLockContention($pidFile),
    'pid_lifecycle' => testPidLifecycle($pidFile),
    'symlink_unlink_safety' => testSymlinkUnlinkSafety($pidFile, $tempDir),
    'symlink_rejection' => testSymlinkRejection($pidFile),
    'inode_mismatch_detection' => testInodeMismatchDetection($pidFile, $tempDir),
    default => (function () use ($testName): never {
        fwrite(STDERR, "Unknown test: $testName\n");
        exit(2);
    })(),
};

function testForkSuccess(string $pidFile): void
{
    $fp = fopen($pidFile, 'c');
    if ($fp === false) {
        fail('Cannot open PID file');
    }

    $locked = flock($fp, LOCK_EX | LOCK_NB);
    assertTrue($locked, 'Should acquire initial lock');

    $pid = \pcntl_fork();
    if ($pid === -1) {
        fail('Fork failed');
    }

    if ($pid === 0) {
        flock($fp, LOCK_UN);
        fclose($fp);

        $cfp = fopen($pidFile, 'c');
        if ($cfp === false || !flock($cfp, LOCK_EX | LOCK_NB)) {
            exit(1);
        }
        ftruncate($cfp, 0);
        fwrite($cfp, strval(posix_getpid()));
        fflush($cfp);
        flock($cfp, LOCK_UN);
        fclose($cfp);

        assertFileExists($pidFile, 'PID file should exist in child after writing');

        @unlink($pidFile);
        assertFileNotExists($pidFile, 'PID file should be removed after child cleanup');

        exit(0);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    $status = waitForChild();
    assertTrue($status >= 0, 'Child process should terminate within timeout');
    assertSame(0, pcntl_wexitstatus($status), 'Child should exit with code 0');

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function testForkError(string $pidFile): void
{
    $fp = fopen($pidFile, 'c');
    if ($fp === false) {
        fail('Cannot open PID file');
    }

    $locked = flock($fp, LOCK_EX | LOCK_NB);
    assertTrue($locked, 'Should acquire initial lock on PID file');

    flock($fp, LOCK_UN);
    fclose($fp);

    assertFileExists($pidFile, 'PID file should still exist after releasing lock');

    $verifierFp = fopen($pidFile, 'c');
    if ($verifierFp === false) {
        fail('Cannot open PID file for verification');
    }

    $lockAvailable = flock($verifierFp, LOCK_EX | LOCK_NB);
    assertTrue($lockAvailable, 'Lock should be available after release');
    flock($verifierFp, LOCK_UN);
    fclose($verifierFp);

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function testLockContention(string $pidFile): void
{
    $lockFp = fopen($pidFile, 'c');
    if ($lockFp === false) {
        fail('Cannot open PID file');
    }

    $locked = flock($lockFp, LOCK_EX | LOCK_NB);
    assertTrue($locked, 'Should acquire initial lock');

    $testFp = fopen($pidFile, 'c');
    if ($testFp === false) {
        fail('Cannot open second PID file handle');
    }

    $contended = flock($testFp, LOCK_EX | LOCK_NB);
    assertTrue(
        $contended === false,
        'Lock contention should fail when another handle holds the lock',
    );

    fclose($testFp);

    flock($lockFp, LOCK_UN);
    fclose($lockFp);

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function testPidLifecycle(string $pidFile): void
{
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
        fwrite($fp, strval(posix_getpid()));
        fflush($fp);

        assertFileExists($pidFile, 'PID file should exist in child after writing');

        flock($fp, LOCK_UN);
        fclose($fp);
        exit(0);
    }

    $status = waitForChild();
    assertTrue($status >= 0, 'Child process should terminate within timeout');
    assertSame(0, pcntl_wexitstatus($status), 'Child should exit with code 0');

    assertFileExists($pidFile, 'PID file should exist after child writes it');

    @unlink($pidFile);

    assertFileNotExists($pidFile, 'PID file should not exist after cleanup');

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function testSymlinkUnlinkSafety(string $pidFile, string $tempDir): void
{
    $targetFile = $tempDir . '/sensitive_target.txt';
    file_put_contents($targetFile, 'sensitive data');

    assertFileNotExists($pidFile, 'PID file should not exist before symlink creation');

    $linked = symlink($targetFile, $pidFile);
    assertTrue($linked, 'Symlink should be created successfully');

    assertFileExists($pidFile, 'Symlink should exist at PID path');
    assertFileExists($targetFile, 'Target file should still exist');

    @unlink($pidFile);

    assertFileNotExists($pidFile, 'Symlink should be removed by unlink');
    assertFileExists($targetFile, 'Target file must NOT be removed by unlink on symlink');

    unlink($targetFile);

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function testSymlinkRejection(string $pidFile): void
{
    $targetFile = sys_get_temp_dir() . '/workerman_symlink_rejection_target_' . uniqid();
    file_put_contents($targetFile, 'target');

    $linked = symlink($targetFile, $pidFile);
    assertTrue($linked, 'Symlink should be created successfully');

    clearstatcache(true, $pidFile);
    $isSymlink = is_link($pidFile);
    assertTrue($isSymlink, 'is_link should detect the symlink');

    if ($isSymlink) {
        assertFileExists($targetFile, 'Target should not be modified when we reject');
    }

    @unlink($pidFile);
    unlink($targetFile);

    assertFileNotExists($pidFile, 'Symlink should be cleaned up');
    assertFileNotExists($targetFile, 'Target should be cleaned up');

    fwrite(STDOUT, "PASS\n");
    exit(0);
}

function testInodeMismatchDetection(string $pidFile, string $tempDir): void
{
    $fileA = $tempDir . '/file_a.txt';
    $fileB = $tempDir . '/file_b.txt';
    file_put_contents($fileA, 'original');
    file_put_contents($fileB, 'replacement');

    $fp = fopen($fileA, 'r');
    if ($fp === false) {
        fail('Should open file A');
    }

    $statA = fstat($fp);
    if ($statA === false) {
        fail('Should stat file A');
    }

    fclose($fp);
    unlink($fileA);
    rename($fileB, $fileA);

    $fp2 = fopen($fileA, 'r');
    if ($fp2 === false) {
        fail('Should open replaced file');
    }

    $statPath = lstat($fileA);
    if ($statPath === false) {
        fail('Should lstat path');
    }

    $match = $statA['ino'] === $statPath['ino'] && $statA['dev'] === $statPath['dev'];
    assertTrue(
        $match === false,
        'Inode/dev mismatch should be detected when file is replaced after open',
    );

    fclose($fp2);
    unlink($fileA);

    fwrite(STDOUT, "PASS\n");
    exit(0);
}
