<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\WaitStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the shared exponential-backoff polling strategy.
 *
 * @see WaitStrategy
 */
final class WaitStrategyTest extends TestCase
{
    /**
     * A condition that is already true should return immediately.
     */
    public function testWaitForReturnsTrueWhenConditionAlreadyMet(): void
    {
        $strategy = new WaitStrategy(initialDelayMs: 1, maxDelayMs: 5);
        $startTime = microtime(true);

        $result = $strategy->waitFor(static fn(): bool => true, 5);

        $elapsed = microtime(true) - $startTime;
        $this->assertTrue($result);
        $this->assertLessThan(1, $elapsed, 'Should return instantly for already-true condition');
    }

    /**
     * A condition that never becomes true should time out.
     */
    public function testWaitForReturnsFalseOnTimeout(): void
    {
        $strategy = new WaitStrategy(initialDelayMs: 1, maxDelayMs: 5);

        $result = $strategy->waitFor(static fn(): bool => false, 0);

        $this->assertFalse($result, 'Should return false immediately with zero timeout');
    }

    /**
     * With a short timeout, a persistently false condition returns false.
     *
     * Uses timeout=1 so the loop runs for at most ~1 second wall time.
     * We just verify the result is false; the exact wall time depends on
     * second-granularity of time() and system scheduling.
     */
    public function testWaitForEventuallyTimesOut(): void
    {
        $strategy = new WaitStrategy(initialDelayMs: 10, maxDelayMs: 20);

        $result = $strategy->waitFor(static fn(): bool => false, 1);

        $this->assertFalse($result);
    }

    /**
     * A condition that becomes true mid-way should return true.
     */
    public function testWaitForReturnsTrueWhenConditionBecomesMet(): void
    {
        $strategy = new WaitStrategy(initialDelayMs: 10, maxDelayMs: 50);
        $counter = 0;

        $result = $strategy->waitFor(
            static function () use (&$counter): bool {
                $counter++;
                return $counter >= 3;
            },
            5,
        );

        $this->assertTrue($result);
        $this->assertGreaterThanOrEqual(3, $counter, 'Condition should have been checked at least 3 times');
    }

    /**
     * A zero-second timeout should return false immediately when condition is false.
     */
    public function testWaitForZeroTimeout(): void
    {
        $strategy = new WaitStrategy(initialDelayMs: 10, maxDelayMs: 50);
        $startTime = microtime(true);

        $result = $strategy->waitFor(static fn(): bool => false, 0);

        $elapsed = microtime(true) - $startTime;
        $this->assertFalse($result);
        $this->assertLessThan(1, $elapsed, 'Should return quickly with zero timeout');
    }

    /**
     * Default constructor values should work (smoke test).
     */
    public function testDefaultConstructor(): void
    {
        $strategy = new WaitStrategy();
        $this->assertInstanceOf(WaitStrategy::class, $strategy);
    }
}
