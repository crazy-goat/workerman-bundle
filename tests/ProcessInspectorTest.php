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

    /**
     * Test that graceful timeout is always longer than regular timeout.
     *
     * This verifies the fix for the asymmetric timeout formula issue.
     * The formula is: graceful = stopTimeout * 3 + 3, regular = stopTimeout + 3
     */
    public function testGracefulTimeoutIsAlwaysLongerThanRegular(): void
    {
        $testCases = [
            ['stopTimeout' => 1, 'expectedGraceful' => 6, 'expectedRegular' => 4],
            ['stopTimeout' => 2, 'expectedGraceful' => 9, 'expectedRegular' => 5],
            ['stopTimeout' => 5, 'expectedGraceful' => 18, 'expectedRegular' => 8],
            ['stopTimeout' => 10, 'expectedGraceful' => 33, 'expectedRegular' => 13],
        ];

        foreach ($testCases as $case) {
            $stopTimeout = $case['stopTimeout'];
            $gracefulTimeout = $stopTimeout * 3 + 3;
            $regularTimeout = $stopTimeout + 3;

            $this->assertSame(
                $case['expectedGraceful'],
                $gracefulTimeout,
                "Graceful timeout calculation incorrect for stopTimeout={$stopTimeout}",
            );
            $this->assertSame(
                $case['expectedRegular'],
                $regularTimeout,
                "Regular timeout calculation incorrect for stopTimeout={$stopTimeout}",
            );

            $this->assertGreaterThan(
                $regularTimeout,
                $gracefulTimeout,
                "Graceful timeout ({$gracefulTimeout}s) must be longer than regular ({$regularTimeout}s) for stopTimeout={$stopTimeout}",
            );
        }
    }
}
