<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Scheduler\Trigger\DateTimeTrigger;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\JitterTrigger;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\PeriodicalTrigger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CrazyGoat\WorkermanBundle\Scheduler\Trigger\JitterTrigger
 */
final class JitterTriggerTest extends TestCase
{
    public function testWrapsAnotherTrigger(): void
    {
        $innerTrigger = new PeriodicalTrigger(60);
        $jitterTrigger = new JitterTrigger($innerTrigger, 5);

        $this->assertInstanceOf(JitterTrigger::class, $jitterTrigger);
    }

    public function testGetNextRunDateAddsJitter(): void
    {
        $innerTrigger = new PeriodicalTrigger(60);
        $jitterTrigger = new JitterTrigger($innerTrigger, 10);
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $innerNextRun = $innerTrigger->getNextRunDate($now);
        $jitterNextRun = $jitterTrigger->getNextRunDate($now);

        $this->assertInstanceOf(\DateTimeImmutable::class, $innerNextRun);
        $this->assertInstanceOf(\DateTimeImmutable::class, $jitterNextRun);
        $this->assertGreaterThanOrEqual($innerNextRun, $jitterNextRun);
        $this->assertLessThanOrEqual($innerNextRun->modify('+10 seconds'), $jitterNextRun);
    }

    public function testGetNextRunDateReturnsNullWhenInnerReturnsNull(): void
    {
        $pastDate = new \DateTimeImmutable('-1 day');
        $innerTrigger = new DateTimeTrigger($pastDate);
        $jitterTrigger = new JitterTrigger($innerTrigger, 5);
        $now = new \DateTimeImmutable();

        $nextRun = $jitterTrigger->getNextRunDate($now);

        $this->assertNull($nextRun);
    }

    public function testToStringIncludesJitterInfo(): void
    {
        $innerTrigger = new PeriodicalTrigger(60);
        $jitterTrigger = new JitterTrigger($innerTrigger, 5);

        $string = (string) $jitterTrigger;

        $this->assertStringContainsString('jitter', $string);
        $this->assertStringContainsString('0-5', $string);
    }

    public function testJitterVaries(): void
    {
        $innerTrigger = new PeriodicalTrigger(60);
        $jitterTrigger = new JitterTrigger($innerTrigger, 5);
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $innerNextRun = $innerTrigger->getNextRunDate($now);
        $this->assertInstanceOf(\DateTimeImmutable::class, $innerNextRun);

        // Collect multiple jitter values
        $values = [];
        for ($i = 0; $i < 50; ++$i) {
            $jitterNextRun = $jitterTrigger->getNextRunDate($now);
            $this->assertInstanceOf(\DateTimeImmutable::class, $jitterNextRun);
            $values[] = $jitterNextRun->getTimestamp() - $innerNextRun->getTimestamp();
        }

        // Verify all values are within bounds
        foreach ($values as $value) {
            $this->assertGreaterThanOrEqual(0, $value);
            $this->assertLessThanOrEqual(5, $value);
        }

        // Verify there's some variation (not all identical)
        $uniqueValues = array_unique($values);
        $this->assertGreaterThan(1, count($uniqueValues), 'Jitter should vary between calls');
    }

    public function testZeroJitter(): void
    {
        $innerTrigger = new PeriodicalTrigger(60);
        $jitterTrigger = new JitterTrigger($innerTrigger, 0);
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $innerNextRun = $innerTrigger->getNextRunDate($now);
        $jitterNextRun = $jitterTrigger->getNextRunDate($now);

        $this->assertInstanceOf(\DateTimeImmutable::class, $innerNextRun);
        $this->assertInstanceOf(\DateTimeImmutable::class, $jitterNextRun);
        $this->assertEquals($innerNextRun->getTimestamp(), $jitterNextRun->getTimestamp());
    }
}
