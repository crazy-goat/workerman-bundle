<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Scheduler\Trigger\DateTimeTrigger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CrazyGoat\WorkermanBundle\Scheduler\Trigger\DateTimeTrigger
 */
final class DateTimeTriggerTest extends TestCase
{
    public function testCreateFromIso8601String(): void
    {
        $trigger = new DateTimeTrigger('2024-12-25T10:00:00+00:00');

        $this->assertInstanceOf(DateTimeTrigger::class, $trigger);
    }

    public function testCreateFromDateTimeImmutable(): void
    {
        $date = new \DateTimeImmutable('2024-12-25 10:00:00');
        $trigger = new DateTimeTrigger($date);

        $this->assertInstanceOf(DateTimeTrigger::class, $trigger);
    }

    public function testCreateFromRelativeDateString(): void
    {
        $trigger = new DateTimeTrigger('+1 hour');

        $this->assertInstanceOf(DateTimeTrigger::class, $trigger);
    }

    public function testInvalidDateStringThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date string');

        new DateTimeTrigger('not a valid date');
    }

    public function testGetNextRunDateReturnsDateWhenInFuture(): void
    {
        $futureDate = new \DateTimeImmutable('+1 day');
        $trigger = new DateTimeTrigger($futureDate);
        $now = new \DateTimeImmutable();

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertInstanceOf(\DateTimeImmutable::class, $nextRun);
        $this->assertEquals($futureDate->format('Y-m-d H:i'), $nextRun->format('Y-m-d H:i'));
    }

    public function testGetNextRunDateReturnsNullWhenInPast(): void
    {
        $pastDate = new \DateTimeImmutable('-1 day');
        $trigger = new DateTimeTrigger($pastDate);
        $now = new \DateTimeImmutable();

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertNull($nextRun);
    }

    public function testGetNextRunDateReturnsNullWhenExactlyNow(): void
    {
        $now = new \DateTimeImmutable();
        $trigger = new DateTimeTrigger($now);

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertNull($nextRun);
    }

    public function testToStringReturnsFormattedDate(): void
    {
        $date = new \DateTimeImmutable('2024-12-25 10:00:00', new \DateTimeZone('UTC'));
        $trigger = new DateTimeTrigger($date);

        $this->assertStringContainsString('2024-12-25', (string) $trigger);
        $this->assertStringContainsString('10:00:00', (string) $trigger);
    }
}
