<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Scheduler\Trigger\DateTimeTrigger;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DateTimeTrigger.
 *
 * @covers \CrazyGoat\WorkermanBundle\Scheduler\Trigger\DateTimeTrigger
 */
final class DateTimeTriggerTest extends TestCase
{
    /**
     * Test creating trigger from ISO8601 string.
     */
    public function testCreateFromIso8601String(): void
    {
        $trigger = new DateTimeTrigger('2024-12-25T10:00:00+00:00');

        $this->assertInstanceOf(DateTimeTrigger::class, $trigger);
    }

    /**
     * Test creating trigger from DateTimeImmutable.
     */
    public function testCreateFromDateTimeImmutable(): void
    {
        $date = new \DateTimeImmutable('2024-12-25 10:00:00');
        $trigger = new DateTimeTrigger($date);

        $this->assertInstanceOf(DateTimeTrigger::class, $trigger);
    }

    /**
     * Test creating trigger from relative date string.
     */
    public function testCreateFromRelativeDateString(): void
    {
        $trigger = new DateTimeTrigger('+1 hour');

        $this->assertInstanceOf(DateTimeTrigger::class, $trigger);
    }

    /**
     * Test that invalid date string throws exception.
     */
    public function testInvalidDateStringThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date string');

        new DateTimeTrigger('not a valid date');
    }

    /**
     * Test getNextRunDate returns the date when it's in the future.
     */
    public function testGetNextRunDateReturnsDateWhenInFuture(): void
    {
        $futureDate = new \DateTimeImmutable('+1 day');
        $trigger = new DateTimeTrigger($futureDate);
        $now = new \DateTimeImmutable();

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertInstanceOf(\DateTimeImmutable::class, $nextRun);
        $this->assertEquals($futureDate->format('Y-m-d H:i'), $nextRun->format('Y-m-d H:i'));
    }

    /**
     * Test getNextRunDate returns null when date is in the past.
     */
    public function testGetNextRunDateReturnsNullWhenInPast(): void
    {
        $pastDate = new \DateTimeImmutable('-1 day');
        $trigger = new DateTimeTrigger($pastDate);
        $now = new \DateTimeImmutable();

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertNull($nextRun);
    }

    /**
     * Test getNextRunDate returns null when date is exactly now.
     */
    public function testGetNextRunDateReturnsNullWhenExactlyNow(): void
    {
        $now = new \DateTimeImmutable();
        $trigger = new DateTimeTrigger($now);

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertNull($nextRun);
    }

    /**
     * Test __toString returns formatted date.
     */
    public function testToStringReturnsFormattedDate(): void
    {
        $date = new \DateTimeImmutable('2024-12-25 10:00:00', new \DateTimeZone('UTC'));
        $trigger = new DateTimeTrigger($date);

        $this->assertStringContainsString('2024-12-25', (string) $trigger);
        $this->assertStringContainsString('10:00:00', (string) $trigger);
    }
}
