<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\ProcessInspector;
use CrazyGoat\WorkermanBundle\Util\Wait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for ProcessInspector process inspection behavior.
 */
final class ProcessInspectorTest extends TestCase
{
    private ProcessInspector $inspector;

    protected function setUp(): void
    {
        $this->inspector = new ProcessInspector();
    }

    /**
     * Invoke the private waitForProcessToStop method via reflection.
     */
    private function invokeWaitForProcessToStop(
        ProcessInspector $inspector,
        int $pid,
        int $stopTimeout,
        bool $graceful,
    ): bool {
        $reflection = new ReflectionClass($inspector);
        $method = $reflection->getMethod('waitForProcessToStop');

        return $method->invoke($inspector, $pid, $stopTimeout, $graceful);
    }

    /**
     * Test that graceful stop respects timeout and doesn't loop infinitely.
     *
     * This is the main regression test for issue #20 - the original bug caused
     * an infinite loop when graceful=true because the timeout was never checked.
     */
    public function testGracefulStopRespectsTimeout(): void
    {
        // Use a non-existent PID (0) which is immediately considered "not alive"
        // This tests that the method returns quickly without infinite looping
        $startTime = microtime(true);
        $result = $this->invokeWaitForProcessToStop($this->inspector, 0, 1, true);
        $elapsed = microtime(true) - $startTime;

        // PID 0 is not alive, so should return true immediately
        $this->assertTrue($result);
        $this->assertLessThan(1, $elapsed, 'Should return immediately for non-existent PID');
    }

    /**
     * Test that regular stop also respects timeout.
     *
     * This is a regression test to ensure regular stop behavior is unchanged.
     */
    public function testRegularStopRespectsTimeout(): void
    {
        // Use a non-existent PID (0) which is immediately considered "not alive"
        $startTime = microtime(true);
        $result = $this->invokeWaitForProcessToStop($this->inspector, 0, 1, false);
        $elapsed = microtime(true) - $startTime;

        // PID 0 is not alive, so should return true immediately
        $this->assertTrue($result);
        $this->assertLessThan(1, $elapsed, 'Should return immediately for non-existent PID');
    }

    public function testTimeoutConstantsExist(): void
    {
        $reflection = new ReflectionClass(ProcessInspector::class);

        $this->assertTrue(
            $reflection->hasConstant('GRACEFUL_TIMEOUT_MULTIPLIER'),
            'GRACEFUL_TIMEOUT_MULTIPLIER constant must exist',
        );
        $this->assertTrue(
            $reflection->hasConstant('TIMEOUT_BUFFER'),
            'TIMEOUT_BUFFER constant must exist',
        );

        $multiplierRef = $reflection->getReflectionConstant('GRACEFUL_TIMEOUT_MULTIPLIER');
        $bufferRef = $reflection->getReflectionConstant('TIMEOUT_BUFFER');

        $this->assertInstanceOf(\ReflectionClassConstant::class, $multiplierRef);
        $this->assertInstanceOf(\ReflectionClassConstant::class, $bufferRef);

        $this->assertTrue($multiplierRef->isPrivate(), 'GRACEFUL_TIMEOUT_MULTIPLIER should be private');
        $this->assertTrue($bufferRef->isPrivate(), 'TIMEOUT_BUFFER should be private');

        $this->assertSame(3, $multiplierRef->getValue(), 'GRACEFUL_TIMEOUT_MULTIPLIER must be 3');
        $this->assertSame(3, $bufferRef->getValue(), 'TIMEOUT_BUFFER must be 3');
    }

    /**
     * Test that graceful timeout is always longer than regular timeout.
     *
     * Reads the actual constant values from ProcessInspector so this test
     * stays in sync if the constants change — no magic-number duplication.
     */
    public function testGracefulTimeoutIsAlwaysLongerThanRegular(): void
    {
        $reflection = new ReflectionClass(ProcessInspector::class);
        $multiplierRef = $reflection->getReflectionConstant('GRACEFUL_TIMEOUT_MULTIPLIER');
        $bufferRef = $reflection->getReflectionConstant('TIMEOUT_BUFFER');
        /** @var int $multiplier */
        $multiplier = $multiplierRef instanceof \ReflectionClassConstant ? $multiplierRef->getValue() : 3;
        /** @var int $buffer */
        $buffer = $bufferRef instanceof \ReflectionClassConstant ? $bufferRef->getValue() : 3;

        $testCases = [
            ['stopTimeout' => 1],
            ['stopTimeout' => 2],
            ['stopTimeout' => 5],
            ['stopTimeout' => 10],
        ];

        foreach ($testCases as $case) {
            $stopTimeout = $case['stopTimeout'];
            $gracefulTimeout = $stopTimeout * $multiplier + $buffer;
            $regularTimeout = $stopTimeout + $buffer;

            $this->assertGreaterThan(
                $regularTimeout,
                $gracefulTimeout,
                "Graceful timeout ({$gracefulTimeout}s) must be longer than regular ({$regularTimeout}s) for stopTimeout={$stopTimeout}",
            );
        }
    }

    /**
     * Regression test for issue #530: `isProcessAlive()` must return false
     * for a dead process on every POSIX platform, including macOS where
     * `/proc` is unavailable.
     *
     * Forks a child, kills it (leaving a zombie until reaped), and asserts
     * that `isProcessAlive()` correctly reports the zombie as dead.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsProcessAliveReturnsFalseForDeadProcess(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $this->assertTrue($this->inspector->isProcessAlive($pid), 'Child should be alive before kill');

            posix_kill($pid, SIGKILL);
            // Poll until the kernel marks the child as dead (zombie),
            // replacing the fixed 50ms guess.
            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($pid), 2);

            $this->assertFalse(
                $this->inspector->isProcessAlive($pid),
                'isProcessAlive() must return false for a dead process on ' . PHP_OS_FAMILY,
            );
        } finally {
            // Reap the zombie so it does not leak into other tests.
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * Regression test for issue #530: `isProcessAlive()` must return true
     * for a running child on every POSIX platform.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsProcessAliveReturnsTrueForRunningProcess(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $this->assertTrue(
                $this->inspector->isProcessAlive($pid),
                'isProcessAlive() must return true for a running process on ' . PHP_OS_FAMILY,
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * Regression test for issue #530: `isProcessAlive()` must return true
     * for a process that is not a direct child of the current process.
     *
     * On non-Linux platforms, `pcntl_waitpid()` returns `-1` for PIDs that
     * are not direct children, so since issue #651 the process state is
     * read via `ps -o stat=`. A running non-child reports a non-zombie
     * state (e.g. `S`/`R`) and must be reported as alive.
     *
     * Uses the parent process's PID (always non-child) as the test target.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsProcessAliveReturnsTrueForNonChildProcess(): void
    {
        $parentPid = posix_getppid();

        $this->assertTrue(
            posix_kill($parentPid, 0),
            'Parent PID must be signalable for this test to be meaningful',
        );

        $this->assertTrue(
            $this->inspector->isProcessAlive($parentPid),
            'isProcessAlive() must return true for a non-child process on ' . PHP_OS_FAMILY,
        );
    }

    /**
     * Regression test for issue #651: `isProcessAlive()` must return false
     * for a ZOMBIE process that is NOT a direct child of the current
     * process.
     *
     * This reproduces the macOS + grpc failure: a daemonized Workerman
     * master is a child of the (hung) daemonize intermediate, not of the
     * CLI process running `workerman:server stop`. When the master dies,
     * the hung intermediate never reaps it, so the master stays a zombie.
     * From the stopper's perspective the zombie master is a non-child:
     * `pcntl_waitpid()` fails with ECHILD, and the old code wrongly treated
     * that as "alive", looping until `stop_timeout`.
     *
     * Setup: fork child A; A forks grandchild B and reports B's PID via a
     * marker file, then sleeps forever without reaping. B kills itself with
     * SIGKILL, so it remains a zombie child of A — a non-child zombie from
     * this test process's perspective. SIGKILL (not `exit()`) is used so
     * the test cannot hang in extension shutdown handlers on grpc hosts
     * (see ProcessTerminator).
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsProcessAliveReturnsFalseForNonChildZombie(): void
    {
        $marker = \sys_get_temp_dir() . '/workerman_zombie_' . \bin2hex(\random_bytes(4));

        $childA = pcntl_fork();
        if ($childA === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($childA === 0) {
            $childB = pcntl_fork();
            if ($childB === -1) {
                exit(1);
            }
            if ($childB > 0) {
                // Child A: publish the grandchild PID atomically (write to
                // a temp file then rename) so the parent never observes an
                // empty marker file and parses PID 0, then sleep forever
                // WITHOUT reaping B, so B stays a zombie.
                $tmp = $marker . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
                \file_put_contents($tmp, (string) $childB);
                \rename($tmp, $marker);
                for (;;) {
                    sleep(1);
                }
            }
            // Grandchild B: die immediately, becoming a zombie under A.
            posix_kill(posix_getpid(), SIGKILL);
            exit(1); // safety net, unreachable when SIGKILL is delivered
        }

        try {
            $this->waitForFile($marker, 3);
            $zombiePid = (int) @file_get_contents($marker);
            $this->assertGreaterThan(0, $zombiePid, 'Child A must publish the grandchild PID');

            // Poll instead of a fixed sleep: B needs a moment to die after
            // the fork. Once dead, B is a zombie non-child and must be
            // reported as not alive on every POSIX platform.
            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($zombiePid), 2);

            $this->assertFalse(
                $this->inspector->isProcessAlive($zombiePid),
                'isProcessAlive() must return false for a non-child zombie on ' . PHP_OS_FAMILY,
            );
        } finally {
            @\unlink($marker);
            // Kill A: B is then reparented to init/launchd and reaped.
            posix_kill($childA, SIGKILL);
            pcntl_waitpid($childA, $status);
        }
    }

    /**
     * Regression test for issue #530: `isProcessAlive()` must return false
     * for a non-existent PID on every POSIX platform.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsProcessAliveReturnsFalseForNonExistentPid(): void
    {
        $this->assertFalse($this->inspector->isProcessAlive(0));
        $this->assertFalse($this->inspector->isProcessAlive(-1));
        // Use a PID that is extremely unlikely to exist.
        $this->assertFalse($this->inspector->isProcessAlive(999_999_999));
    }

    /**
     * Regression test for issue #530: `getParentPid()` must not crash on
     * non-Linux platforms where `/proc` is unavailable. It returns 0 as
     * a safe fallback (the caller treats 0 as "no parent").
     *
     * @requires OS Darwin
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testGetParentPidReturnsZeroOnNonLinux(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $this->assertSame(
                0,
                $this->inspector->getParentPid($pid),
                'getParentPid() must return 0 on non-Linux platforms',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    // ──────────────────────────────────────────────
    // Fingerprint verification (issue #327)
    // ──────────────────────────────────────────────

    /**
     * Regression test for issue #327: `matchesFingerprint()` must return
     * true for the current process when given a fingerprint captured
     * from the current process.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testMatchesFingerprintReturnsTrueForCurrentProcess(): void
    {
        $fingerprint = \CrazyGoat\WorkermanBundle\MasterFingerprint::capture();

        $this->assertTrue(
            $this->inspector->matchesFingerprint($fingerprint->pid, $fingerprint),
            'matchesFingerprint() must return true for the current process with its own fingerprint',
        );
    }

    /**
     * Regression test for issue #327: `matchesFingerprint()` must return
     * false for a PID that does not match the recorded fingerprint PID.
     *
     * Uses a forked child as the "wrong" PID — the child is alive but
     * its PID is different from the fingerprint's PID.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testMatchesFingerprintReturnsFalseForDifferentPid(): void
    {
        $fingerprint = \CrazyGoat\WorkermanBundle\MasterFingerprint::capture();

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $this->assertFalse(
                $this->inspector->matchesFingerprint($pid, $fingerprint),
                'matchesFingerprint() must return false for a PID different from the fingerprint PID',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * Regression test for issue #327: `matchesFingerprint()` must return
     * false for a non-existent PID.
     */
    public function testMatchesFingerprintReturnsFalseForNonExistentPid(): void
    {
        $fingerprint = \CrazyGoat\WorkermanBundle\MasterFingerprint::capture();

        $this->assertFalse($this->inspector->matchesFingerprint(0, $fingerprint));
        $this->assertFalse($this->inspector->matchesFingerprint(-1, $fingerprint));
        $this->assertFalse($this->inspector->matchesFingerprint(999_999_999, $fingerprint));
    }

    /**
     * Regression test for issue #327: `matchesFingerprint()` must return
     * false when the fingerprint PID is invalid (zero or negative).
     */
    public function testMatchesFingerprintReturnsFalseForInvalidFingerprintPid(): void
    {
        $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint(0, 0, 0);
        $currentPid = \getmypid();
        $this->assertIsInt($currentPid);

        $this->assertFalse(
            $this->inspector->matchesFingerprint($currentPid, $fingerprint),
            'matchesFingerprint() must return false when fingerprint PID is invalid',
        );
    }

    /**
     * Regression test for issue #327: `isMasterRunning()` with a valid
     * fingerprint must return true for the current process.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsMasterRunningWithValidFingerprint(): void
    {
        $fingerprint = \CrazyGoat\WorkermanBundle\MasterFingerprint::capture();

        $this->assertTrue(
            $this->inspector->isMasterRunning($fingerprint->pid, $fingerprint),
            'isMasterRunning() must return true when fingerprint matches the candidate PID',
        );
    }

    /**
     * Regression test for issue #327: `isMasterRunning()` with a valid
     * fingerprint must return false for a PID different from the
     * fingerprint PID, even if that PID is alive.
     *
     * This is the core security test: an unrelated co-located process
     * whose PID happens to be alive must not be misidentified as the
     * Workerman master.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsMasterRunningWithFingerprintRejectsUnrelatedProcess(): void
    {
        $fingerprint = \CrazyGoat\WorkermanBundle\MasterFingerprint::capture();

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $this->assertFalse(
                $this->inspector->isMasterRunning($pid, $fingerprint),
                'isMasterRunning() with fingerprint must reject a PID different from the fingerprint PID',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * Regression test for issue #584: `isMasterRunning()` without a
     * fingerprint must reject a live process whose cmdline contains
     * "php" but is not a Workerman master.
     *
     * The old fallback accepted any cmdline containing the substring
     * "php", which made the check vacuous — every PHP process matched,
     * including the caller itself. On non-Linux hosts the old code
     * returned true unconditionally; it now fails closed.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsMasterRunningWithoutFingerprintRejectsPlainPhpProcess(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            // Without a fingerprint, the legacy cmdline check is used.
            // The forked child is a plain PHP process: its cmdline
            // contains "php" but not the Workerman master title, so
            // the check must reject it on every platform.
            $this->assertFalse(
                $this->inspector->isMasterRunning($pid),
                'isMasterRunning() without fingerprint must reject a plain PHP process',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * Regression test for issue #584: `isMasterRunning()` without a
     * fingerprint accepts a live process whose cmdline carries the
     * process title Workerman assigns to its master process.
     *
     * The title is simulated with `execve()` (argv[0]) rather than
     * `cli_set_process_title()`, whose effect on /proc/$pid/cmdline
     * varies by PHP build (PHP >= 8.5 keeps the original argv on some
     * builds) — execve is kernel behaviour, identical everywhere.
     *
     * @requires OS Linux
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsMasterRunningWithoutFingerprintAcceptsWorkermanMasterTitle(): void
    {
        $pid = $this->forkChildWithMasterTitle();

        try {
            $this->assertTrue(
                $this->inspector->isMasterRunning($pid),
                'isMasterRunning() must accept a process carrying the Workerman master title',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * Regression test for issue #584: `killOrphanedIntermediateFork()`
     * without a fingerprint must kill a process carrying the Workerman
     * master title.
     *
     * @requires OS Linux
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testKillOrphanedIntermediateForkWithoutFingerprintKillsMasterTitleProcess(): void
    {
        $pid = $this->forkChildWithMasterTitle();

        try {
            $this->inspector->killOrphanedIntermediateFork($pid);

            $this->waitForProcessDeath($pid);

            $this->assertFalse(
                $this->inspector->isProcessAlive($pid),
                'killOrphanedIntermediateFork() must kill a process carrying the Workerman master title',
            );
        } finally {
            if ($this->inspector->isProcessAlive($pid)) {
                posix_kill($pid, SIGKILL);
            }
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * Poll until the process is dead, so signal delivery does not depend
     * on a fixed sleep (flaky under heavy CI load).
     */
    private function waitForProcessDeath(int $pid): void
    {
        Wait::until(fn(): bool => !$this->inspector->isProcessAlive($pid), 1);
    }

    /**
     * Fork a child that replaces itself with a process carrying the
     * Workerman master process title in its command line.
     *
     * The title is simulated with `bash -c 'exec -a ...'` (execve
     * argv[0]) rather than `cli_set_process_title()`, whose effect on
     * /proc/$pid/cmdline varies by PHP build (PHP >= 8.5 keeps the
     * original argv on some builds). Execve is kernel behaviour,
     * identical everywhere.
     *
     * A marker file (created by the child AFTER the exec) synchronises
     * the fork, so the caller never reads /proc before the title is in
     * place.
     */
    private function forkChildWithMasterTitle(): int
    {
        $marker = \sys_get_temp_dir() . '/workerman_master_title_' . \bin2hex(\random_bytes(4));
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            $this->execAsMasterTitle($marker);
        }

        $this->waitForFile($marker, 3);
        @\unlink($marker);

        return $pid;
    }

    /**
     * Replace the current process with one that carries the Workerman
     * master process title in its command line.
     *
     * The exec'd process redirects stdio to `/dev/null` so it cannot
     * keep PHPUnit's output descriptors open, and it uses a tiny PHP
     * loop (not `/bin/sh`) because the shell loop ignores SIGINT/SIGQUIT
     * on macOS and makes stop() tests hang in `waitpid()`.
     */
    private function execAsMasterTitle(string $marker): never
    {
        $title = 'WorkerMan: master process  start_file=' . __FILE__;
        $script = <<<'PHP'
pcntl_async_signals(true);
pcntl_signal(SIGINT, static function (): void { exit(0); });
pcntl_signal(SIGQUIT, static function (): void { exit(0); });
pcntl_signal(SIGTERM, static function (): void { exit(0); });
touch(__MARKER__);
while (true) {
    sleep(1);
}
PHP;
        $script = str_replace('__MARKER__', var_export($marker, true), $script);
        $command = 'exec -a ' . escapeshellarg($title)
            . ' ' . escapeshellarg(\PHP_BINARY)
            . ' -r ' . escapeshellarg($script)
            . ' < /dev/null > /dev/null 2>&1';

        pcntl_exec('/bin/bash', ['-c', $command]);
        \fwrite(\STDERR, 'Unable to exec master-title process' . \PHP_EOL);
        exit(1);
    }

    private function waitForFile(string $path, int $timeout): void
    {
        Wait::until(static fn(): bool => \file_exists($path), $timeout);
    }

    /**
     * Regression test for issue #327: `killOrphanedIntermediateFork()`
     * with a valid fingerprint must kill the process when the parent
     * PID matches the fingerprint.
     *
     * Forks a child, captures its fingerprint, then calls
     * `killOrphanedIntermediateFork()` with the child's PID and the
     * fingerprint. The child must be killed.
     *
     * @requires OS Linux
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testKillOrphanedIntermediateForkWithMatchingFingerprintKillsProcess(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        // Capture the fingerprint from the child process by reading
        // /proc/$pid/stat. We construct a fingerprint manually because
        // MasterFingerprint::capture() reads the current process.
        $fingerprint = $this->captureFingerprintForPid($pid);

        try {
            $this->inspector->killOrphanedIntermediateFork($pid, $fingerprint);

            $this->waitForProcessDeath($pid);

            $this->assertFalse(
                $this->inspector->isProcessAlive($pid),
                'killOrphanedIntermediateFork() must kill the process when fingerprint matches',
            );
        } finally {
            // Reap if still alive (shouldn't be, but be safe).
            if ($this->inspector->isProcessAlive($pid)) {
                posix_kill($pid, SIGKILL);
            }
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * Regression test for issue #327: `killOrphanedIntermediateFork()`
     * with a fingerprint must NOT kill a process whose PID does not
     * match the fingerprint PID.
     *
     * This is the core negative test from the issue: an unrelated
     * co-located process whose command line contains "WorkerMan" must
     * not be signaled.
     *
     * @requires OS Linux
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testKillOrphanedIntermediateForkWithFingerprintDoesNotKillUnrelatedProcess(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        // Use a fingerprint with a different PID — the child is alive
        // but its PID does not match the fingerprint's PID.
        $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint(
            pid: $pid + 1_000_000, // PID that does not exist
            startTime: 0,
            uid: \posix_getuid(),
        );

        try {
            $this->inspector->killOrphanedIntermediateFork($pid, $fingerprint);

            // Poll briefly for death — the kill should NOT have been sent,
            // so the condition never becomes true and Wait::until times out
            // after 1s, which is the "did it die?" observation window.
            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($pid), 1);

            $this->assertTrue(
                $this->inspector->isProcessAlive($pid),
                'killOrphanedIntermediateFork() must NOT kill a process whose PID does not match the fingerprint',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * Regression test for issue #327/#584: `killOrphanedIntermediateFork()`
     * without a fingerprint must fall back to the legacy cmdline check.
     *
     * On Linux, the legacy check kills the process only if its cmdline
     * carries the Workerman master title. A forked child's cmdline
     * contains "php" but not that title, so the legacy check does NOT
     * kill it.
     *
     * @requires OS Linux
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testKillOrphanedIntermediateForkWithoutFingerprintUsesLegacyCheck(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            // Without a fingerprint, the legacy cmdline check is used.
            // The forked child's cmdline contains "php" but not "WorkerMan",
            // so the check does NOT kill it.
            $this->inspector->killOrphanedIntermediateFork($pid);

            // Poll briefly for death — the legacy cmdline check should NOT
            // kill a non-WorkerMan process, so Wait::until times out after 1s.
            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($pid), 1);

            $this->assertTrue(
                $this->inspector->isProcessAlive($pid),
                'killOrphanedIntermediateFork() without fingerprint must use legacy cmdline check (no kill for non-WorkerMan cmdline)',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * Capture a fingerprint for an arbitrary PID by reading
     * /proc/$pid/stat and /proc/$pid/status.
     *
     * Used by tests that need a fingerprint matching a forked child.
     *
     * @requires OS Linux
     */
    private function captureFingerprintForPid(int $pid): \CrazyGoat\WorkermanBundle\MasterFingerprint
    {
        $startTime = 0;
        $statFile = "/proc/{$pid}/stat";
        if (\is_readable($statFile)) {
            $content = \file_get_contents($statFile);
            if (\is_string($content)) {
                $closeParen = \strrpos($content, ')');
                if ($closeParen !== false) {
                    $afterParen = \substr($content, $closeParen + 1);
                    $afterParts = \preg_split('/\s+/', \trim($afterParen));
                    if (\is_array($afterParts) && \count($afterParts) >= 20) {
                        $startTime = (int) $afterParts[19];
                    }
                }
            }
        }

        $uid = \posix_getuid();
        $statusFile = "/proc/{$pid}/status";
        if (\is_readable($statusFile)) {
            $content = \file_get_contents($statusFile);
            if (\is_string($content) && \preg_match('/^Uid:\s+(\d+)/m', $content, $matches)) {
                $uid = (int) $matches[1];
            }
        }

        return new \CrazyGoat\WorkermanBundle\MasterFingerprint($pid, $startTime, $uid);
    }

    /**
     * Invoke a private method on ProcessInspector via reflection.
     */
    private function invokePrivateMethod(string $name, int $pid): mixed
    {
        $reflection = new ReflectionClass($this->inspector);
        $method = $reflection->getMethod($name);

        return $method->invoke($this->inspector, $pid);
    }

    /**
     * Regression test for issue #651 round-1 finding R2: `readProcessStateViaPs()`
     * must return an empty string for a non-existent PID (ps exits non-zero,
     * PID is unsignalable) on non-Linux POSIX.
     *
     * @requires OS Darwin
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testReadProcessStateViaPsReturnsEmptyForNonExistentPid(): void
    {
        if (!\function_exists('exec')) {
            $this->markTestSkipped('exec() is disabled, cannot test ps path');
        }

        $state = $this->invokePrivateMethod('readProcessStateViaPs', 999_999_999);

        $this->assertSame('', $state, 'ps must return empty string for a non-existent PID');
    }

    /**
     * Regression test for issue #651 round-1 finding R2: `readProcessStateViaPs()`
     * must return a non-empty state string for a running PID on non-Linux POSIX.
     *
     * @requires OS Darwin
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testReadProcessStateViaPsReturnsStateForRunningPid(): void
    {
        if (!\function_exists('exec')) {
            $this->markTestSkipped('exec() is disabled, cannot test ps path');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $state = $this->invokePrivateMethod('readProcessStateViaPs', $pid);

            $this->assertNotSame('', $state, 'ps must return a non-empty state for a running PID');
            $this->assertFalse(
                str_starts_with($state, 'Z'),
                'ps must not report a running process as a zombie',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * Regression test for issue #651 round-1 finding R1/R2: `isAliveNonLinux()`
     * must report a non-existent PID as not alive (covers the non-zero ps exit
     * + unsignalable PID branch on non-Linux POSIX).
     *
     * @requires OS Darwin
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsAliveNonLinuxReturnsFalseForNonExistentPid(): void
    {
        $this->assertFalse(
            $this->invokePrivateMethod('isAliveNonLinux', 999_999_999),
            'isAliveNonLinux() must return false for a non-existent PID',
        );
    }

    /**
     * Regression test for issue #651 round-1 finding R1/R2: `isAliveNonLinux()`
     * must report a running direct child as alive (pcntl_waitpid returns 0 →
     * alive, ps path not reached). Real non-child ps coverage is provided by
     * `testIsProcessAliveReturnsTrueForNonChildProcess`.
     *
     * @requires OS Darwin
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsAliveNonLinuxReturnsTrueForRunningPid(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $this->assertTrue(
                $this->invokePrivateMethod('isAliveNonLinux', $pid),
                'isAliveNonLinux() must return true for a running direct child',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }
}
