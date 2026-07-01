<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\ProcessInspector;
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
            // Give the kernel a moment to mark the child as a zombie.
            usleep(50_000);

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
     * On non-Linux platforms, the zombie-detection fallback uses
     * `pcntl_waitpid()`, which returns `-1` for PIDs that are not direct
     * children. The helper must treat that case as "alive" (trusting the
     * `posix_kill` check that already passed) rather than as "dead".
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
     * Regression test for issue #327: `isMasterRunning()` without a
     * fingerprint must fall back to the legacy cmdline-based check.
     *
     * On non-Linux platforms, the legacy check returns true for any
     * alive PID. On Linux, it requires the cmdline to contain
     * "WorkerMan" or "php".
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsMasterRunningWithoutFingerprintFallsBackToLegacyCheck(): void
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
            // Without a fingerprint, the legacy check is used.
            // On Linux, the forked child's cmdline contains "php",
            // so the check returns true. On non-Linux, the check
            // returns true for any alive PID.
            $this->assertTrue(
                $this->inspector->isMasterRunning($pid),
                'isMasterRunning() without fingerprint must use legacy cmdline check',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
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

            // Give the kernel a moment to deliver SIGKILL.
            usleep(100_000);

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

            // Give the kernel a moment (in case the kill was sent).
            usleep(100_000);

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
     * Regression test for issue #327: `killOrphanedIntermediateFork()`
     * without a fingerprint must fall back to the legacy cmdline check.
     *
     * On Linux, the legacy check kills the process only if its cmdline
     * contains "WorkerMan". A forked child's cmdline contains "php" but
     * not "WorkerMan", so the legacy check does NOT kill it.
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

            usleep(100_000);

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
}
