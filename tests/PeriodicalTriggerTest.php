<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Exception\InvalidTriggerException;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\PeriodicalTrigger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CrazyGoat\WorkermanBundle\Scheduler\Trigger\PeriodicalTrigger
 */
final class PeriodicalTriggerTest extends TestCase
{
    public function testCreateFromIntegerSeconds(): void
    {
        $trigger = new PeriodicalTrigger(60);

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
        $this->assertSame('every 60', (string) $trigger);
    }

    public function testCreateFromIso8601Duration(): void
    {
        $trigger = new PeriodicalTrigger('PT1H');

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
        $this->assertSame('DateInterval (PT1H)', (string) $trigger);
    }

    public function testCreateFromRelativeDateString(): void
    {
        $trigger = new PeriodicalTrigger('+1 hour');

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
        $this->assertSame('every +1 hour', (string) $trigger);
    }

    public function testCreateFromDateInterval(): void
    {
        $interval = new \DateInterval('PT30M');
        $trigger = new PeriodicalTrigger($interval);

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
        $this->assertSame('DateInterval', (string) $trigger);
    }

    public function testCreateFromRelativeDateStringInterval(): void
    {
        $interval = \DateInterval::createFromDateString('+1 hour');
        $trigger = new PeriodicalTrigger($interval);

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
        $this->assertSame('DateInterval', (string) $trigger);
    }

    public function testInvalidIntervalThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid interval');

        new PeriodicalTrigger('not a valid interval');
    }

    /**
     * A zero or negative interval would make getNextRunDate() return null
     * forever, silently never scheduling the task (issue #667): it must be
     * rejected at construction time instead.
     *
     * @dataProvider nonPositiveIntervalProvider
     */
    public function testNonPositiveIntervalThrowsException(string|int|\DateInterval $interval): void
    {
        $this->expectException(InvalidTriggerException::class);
        $this->expectExceptionMessage('positive duration');

        new PeriodicalTrigger($interval);
    }

    /**
     * @return array<string, array{string|int|\DateInterval}>
     */
    public static function nonPositiveIntervalProvider(): array
    {
        $inverted = new \DateInterval('P1D');
        $inverted->invert = 1;

        return [
            'zero seconds int' => [0],
            'negative int' => [-5],
            'zero iso duration' => ['PT0S'],
            'zero relative string' => ['0 seconds'],
            'negative relative string' => ['-1 second'],
            'zero DateInterval' => [new \DateInterval('PT0S')],
            'inverted DateInterval' => [$inverted],
        ];
    }

    public function testMixedSignIntervalIsAccepted(): void
    {
        // '-1 day +25 hours' nets +1 hour forward: a field-wise positivity
        // check would wrongly reject it, the add-based one must accept it.
        $trigger = new PeriodicalTrigger('-1 day +25 hours');
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertSame('every -1 day +25 hours', (string) $trigger);
        $this->assertInstanceOf(\DateTimeImmutable::class, $nextRun);
        $this->assertGreaterThan($now, $nextRun);
    }

    public function testGetNextRunDateReturnsFutureDate(): void
    {
        $trigger = new PeriodicalTrigger(60);
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertInstanceOf(\DateTimeImmutable::class, $nextRun);
        $this->assertGreaterThan($now, $nextRun);
    }

    public function testGetNextRunDateCalculationForSeconds(): void
    {
        $trigger = new PeriodicalTrigger(60);
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertInstanceOf(\DateTimeImmutable::class, $nextRun);
        $this->assertSame('2024-01-15 12:01:00', $nextRun->format('Y-m-d H:i:s'));
    }

    public function testGetNextRunDateCalculationForHours(): void
    {
        $trigger = new PeriodicalTrigger('+1 hour');
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertInstanceOf(\DateTimeImmutable::class, $nextRun);
        $this->assertSame('2024-01-15 13:00:00', $nextRun->format('Y-m-d H:i:s'));
    }

    public function testGetNextRunDateCalculationForDays(): void
    {
        $trigger = new PeriodicalTrigger('+1 day');
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertInstanceOf(\DateTimeImmutable::class, $nextRun);
        $this->assertSame('2024-01-16 12:00:00', $nextRun->format('Y-m-d H:i:s'));
    }

    public function testGetNextRunDateCalculationForIso8601Duration(): void
    {
        $trigger = new PeriodicalTrigger('PT2H30M');
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertInstanceOf(\DateTimeImmutable::class, $nextRun);
        $this->assertSame('2024-01-15 14:30:00', $nextRun->format('Y-m-d H:i:s'));
    }

    public function testGetNextRunDatePreservesSubSecondPrecision(): void
    {
        $interval = \DateInterval::createFromDateString('500 ms');
        $this->assertNotFalse($interval);
        $trigger = new PeriodicalTrigger($interval);
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertInstanceOf(\DateTimeImmutable::class, $nextRun);
        $this->assertSame('2024-01-15 12:00:00.500000', $nextRun->format('Y-m-d H:i:s.u'));
    }

    /**
     * @dataProvider intervalFormatProvider
     */
    public function testVariousIntervalFormats(string $interval, string $expectedNextRun): void
    {
        $trigger = new PeriodicalTrigger($interval);
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertInstanceOf(\DateTimeImmutable::class, $nextRun);
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
