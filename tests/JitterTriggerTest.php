<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Scheduler\Trigger\DateTimeTrigger;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\JitterTrigger;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\PeriodicalTrigger;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JitterTrigger.
 *
 * @covers \CrazyGoat\WorkermanBundle\Scheduler\Trigger\JitterTrigger
 */
final class JitterTriggerTest extends TestCase
{
    /**
     * Test that JitterTrigger wraps another trigger.
     */
    public function testWrapsAnotherTrigger(): void
    {
        $innerTrigger = new PeriodicalTrigger(60);
        $jitterTrigger = new JitterTrigger($innerTrigger, 5);

        $this->assertInstanceOf(JitterTrigger::class, $jitterTrigger);
    }

    /**
     * Test that getNextRunDate adds jitter to the inner trigger's date.
     */
    public function testGetNextRunDateAddsJitter(): void
    {
        $innerTrigger = new PeriodicalTrigger(60);
        $jitterTrigger = new JitterTrigger($innerTrigger, 10);
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $innerNextRun = $innerTrigger->getNextRunDate($now);
        $jitterNextRun = $jitterTrigger->getNextRunDate($now);

        // Jitter should add 0-10 seconds
        $this->assertGreaterThanOrEqual($innerNextRun, $jitterNextRun);
        $this->assertLessThanOrEqual($innerNextRun->modify('+10 seconds'), $jitterNextRun);
    }

    /**
     * Test that getNextRunDate returns null when inner trigger returns null.
     */
    public function testGetNextRunDateReturnsNullWhenInnerReturnsNull(): void
    {
        // DateTimeTrigger with past date returns null
        $pastDate = new \DateTimeImmutable('-1 day');
        $innerTrigger = new DateTimeTrigger($pastDate);
        $jitterTrigger = new JitterTrigger($innerTrigger, 5);
        $now = new \DateTimeImmutable();

        $nextRun = $jitterTrigger->getNextRunDate($now);

        $this->assertNull($nextRun);
    }

    /**
     * Test __toString includes jitter information.
     */
    public function testToStringIncludesJitterInfo(): void
    {
        $innerTrigger = new PeriodicalTrigger(60);
        $jitterTrigger = new JitterTrigger($innerTrigger, 5);

        $string = (string) $jitterTrigger;

        $this->assertStringContainsString('jitter', $string);
        $this->assertStringContainsString('0-5', $string);
    }

    /**
     * Test that jitter is within bounds (statistical test).
     *
     * This test runs multiple times to verify jitter stays within bounds.
     */
    public function testJitterStaysWithinBounds(): void
    {
        $innerTrigger = new PeriodicalTrigger(60);
        $maxJitter = 5;
        $jitterTrigger = new JitterTrigger($innerTrigger, $maxJitter);
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $innerNextRun = $innerTrigger->getNextRunDate($now);

        // Run multiple times to check bounds
        for ($i = 0; $i < 20; ++$i) {
            $jitterNextRun = $jitterTrigger->getNextRunDate($now);

            $diff = $jitterNextRun->getTimestamp() - $innerNextRun->getTimestamp();

            $this->assertGreaterThanOrEqual(0, $diff, 'Jitter should not make date earlier');
            $this->assertLessThanOrEqual($maxJitter, $diff, "Jitter should not exceed {$maxJitter} seconds");
        }
    }

    /**
     * Test with zero jitter (should behave like inner trigger).
     */
    public function testZeroJitter(): void
    {
        $innerTrigger = new PeriodicalTrigger(60);
        $jitterTrigger = new JitterTrigger($innerTrigger, 0);
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $innerNextRun = $innerTrigger->getNextRunDate($now);
        $jitterNextRun = $jitterTrigger->getNextRunDate($now);

        // With 0 jitter, should be exactly the same
        $this->assertEquals($innerNextRun->getTimestamp(), $jitterNextRun->getTimestamp());
    }
}
