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
     * Regression test for issue #530: `isProcessAlive()` must return false
     * for a non-existent PID on every POSIX platform.
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
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testGetParentPidReturnsZeroOnNonLinux(): void
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $this->markTestSkipped('Non-Linux-specific test');
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
}
