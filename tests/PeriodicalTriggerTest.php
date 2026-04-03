<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Scheduler\Trigger\PeriodicalTrigger;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PeriodicalTrigger.
 *
 * @covers \CrazyGoat\WorkermanBundle\Scheduler\Trigger\PeriodicalTrigger
 */
final class PeriodicalTriggerTest extends TestCase
{
    /**
     * Test creating trigger from integer seconds.
     */
    public function testCreateFromIntegerSeconds(): void
    {
        $trigger = new PeriodicalTrigger(60);

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
        $this->assertSame('every 60', (string) $trigger);
    }

    /**
     * Test creating trigger from ISO8601 duration.
     */
    public function testCreateFromIso8601Duration(): void
    {
        $trigger = new PeriodicalTrigger('PT1H');

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
        $this->assertSame('DateInterval (PT1H)', (string) $trigger);
    }

    /**
     * Test creating trigger from relative date string.
     */
    public function testCreateFromRelativeDateString(): void
    {
        $trigger = new PeriodicalTrigger('+1 hour');

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
        $this->assertSame('every +1 hour', (string) $trigger);
    }

    /**
     * Test creating trigger from DateInterval.
     */
    public function testCreateFromDateInterval(): void
    {
        $interval = new \DateInterval('PT30M');
        $trigger = new PeriodicalTrigger($interval);

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
    }

    /**
     * Test that invalid interval throws exception.
     */
    public function testInvalidIntervalThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid interval');

        new PeriodicalTrigger('not a valid interval');
    }

    /**
     * Test getNextRunDate returns future date.
     */
    public function testGetNextRunDateReturnsFutureDate(): void
    {
        $trigger = new PeriodicalTrigger(60); // Every 60 seconds
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertInstanceOf(\DateTimeImmutable::class, $nextRun);
        $this->assertGreaterThan($now, $nextRun);
    }

    /**
     * Test getNextRunDate calculation for seconds.
     */
    public function testGetNextRunDateCalculationForSeconds(): void
    {
        $trigger = new PeriodicalTrigger(60); // Every 60 seconds
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertSame('2024-01-15 12:01:00', $nextRun->format('Y-m-d H:i:s'));
    }

    /**
     * Test getNextRunDate calculation for hours.
     */
    public function testGetNextRunDateCalculationForHours(): void
    {
        $trigger = new PeriodicalTrigger('+1 hour');
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertSame('2024-01-15 13:00:00', $nextRun->format('Y-m-d H:i:s'));
    }

    /**
     * Test getNextRunDate calculation for days.
     */
    public function testGetNextRunDateCalculationForDays(): void
    {
        $trigger = new PeriodicalTrigger('+1 day');
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertSame('2024-01-16 12:00:00', $nextRun->format('Y-m-d H:i:s'));
    }

    /**
     * Test getNextRunDate calculation for ISO8601 duration.
     */
    public function testGetNextRunDateCalculationForIso8601Duration(): void
    {
        $trigger = new PeriodicalTrigger('PT2H30M');
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertSame('2024-01-15 14:30:00', $nextRun->format('Y-m-d H:i:s'));
    }

    /**
     * Test that trigger works with various interval formats.
     *
     * @dataProvider intervalFormatProvider
     */
    public function testVariousIntervalFormats(string $interval, string $expectedNextRun): void
    {
        $trigger = new PeriodicalTrigger($interval);
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertSame($expectedNextRun, $nextRun->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function intervalFormatProvider(): array
    {
        return [
            '30 minutes' => ['+30 minutes', '2024-01-15 12:30:00'],
            '2 hours' => ['+2 hours', '2024-01-15 14:00:00'],
            '1 day' => ['+1 day', '2024-01-16 12:00:00'],
            '1 week' => ['+1 week', '2024-01-22 12:00:00'],
        ];
    }
}
