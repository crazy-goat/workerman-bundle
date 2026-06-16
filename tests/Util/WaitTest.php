<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Util;

use CrazyGoat\WorkermanBundle\Util\Wait;
use PHPUnit\Framework\TestCase;

final class WaitTest extends TestCase
{
    public function testReturnsTrueImmediatelyWhenConditionAlreadySatisfied(): void
    {
        $calls = 0;
        $startTime = microtime(true);
        $result = Wait::until(static function () use (&$calls): bool {
            ++$calls;
            return true;
        }, 5);
        $elapsed = microtime(true) - $startTime;

        $this->assertTrue($result);
        $this->assertSame(1, $calls, 'Condition must be evaluated exactly once when already satisfied');
        $this->assertLessThan(0.05, $elapsed, 'Must return without sleeping');
    }

    public function testReturnsFalseWhenTimeoutIsZeroAndConditionUnsatisfied(): void
    {
        $startTime = microtime(true);
        $result = Wait::until(static fn(): bool => false, 0);
        $elapsed = microtime(true) - $startTime;

        $this->assertFalse($result);
        $this->assertLessThan(0.05, $elapsed, 'Must return immediately when timeout is 0');
    }

    public function testReturnsTrueWhenConditionBecomesSatisfiedDuringPolling(): void
    {
        $deadline = microtime(true) + 0.1;
        $result = Wait::until(
            static fn(): bool => microtime(true) >= $deadline,
            5,
            initialDelayMs: 5,
            maxDelayMs: 20,
        );

        $this->assertTrue($result);
    }

    public function testTotalWallTimeBoundedByTimeout(): void
    {
        $startTime = microtime(true);
        $result = Wait::until(
            static fn(): bool => false,
            1,
            initialDelayMs: 10,
            maxDelayMs: 50,
        );
        $elapsed = microtime(true) - $startTime;

        $this->assertFalse($result);
        $this->assertGreaterThanOrEqual(1.0, $elapsed, 'Should wait at least the timeout');
        $this->assertLessThan(1.3, $elapsed, 'Should not significantly exceed the timeout');
    }

    public function testUsesExponentialBackoffCappedAtMaxDelay(): void
    {
        $timestamps = [];
        Wait::until(
            static function () use (&$timestamps): bool {
                $timestamps[] = microtime(true);
                return false;
            },
            1,
            initialDelayMs: 10,
            maxDelayMs: 40,
        );

        $this->assertGreaterThanOrEqual(4, \count($timestamps), 'Must poll multiple times within 1s');

        $firstGap = ($timestamps[1] - $timestamps[0]) * 1000;
        $this->assertGreaterThanOrEqual(8.0, $firstGap, 'First gap should be ~10ms (initial delay)');
        $this->assertLessThan(30.0, $firstGap, 'First gap should not exceed maxDelay early');

        $lastIndex = \count($timestamps) - 1;
        $lastGap = ($timestamps[$lastIndex] - $timestamps[$lastIndex - 1]) * 1000;
        $this->assertLessThan(80.0, $lastGap, 'Sleeps must be capped at maxDelayMs (with scheduling jitter)');
    }

    public function testThrowsOnNegativeTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be non-negative');

        Wait::until(static fn(): bool => true, -1);
    }

    public function testThrowsOnZeroInitialDelay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Initial delay must be at least 1ms');

        Wait::until(static fn(): bool => true, 1, initialDelayMs: 0);
    }

    public function testThrowsOnNegativeInitialDelay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Initial delay must be at least 1ms');

        Wait::until(static fn(): bool => true, 1, initialDelayMs: -5);
    }

    public function testThrowsWhenMaxDelayLessThanInitial(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Max delay (5ms) must be greater than or equal to initial delay (10ms)');

        Wait::until(static fn(): bool => true, 1, initialDelayMs: 10, maxDelayMs: 5);
    }

    public function testAcceptsEqualInitialAndMaxDelay(): void
    {
        $result = Wait::until(static fn(): bool => true, 1, initialDelayMs: 25, maxDelayMs: 25);

        $this->assertTrue($result);
    }

    public function testConstructorIsPrivate(): void
    {
        $reflection = new \ReflectionClass(Wait::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate(), 'Wait should not be instantiable');
    }

    public function testDefaultsExposedAsConstants(): void
    {
        $this->assertSame(10, Wait::DEFAULT_INITIAL_DELAY_MS);
        $this->assertSame(250, Wait::DEFAULT_MAX_DELAY_MS);
    }
}
