<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Scheduler\Trigger\CronExpressionTrigger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CrazyGoat\WorkermanBundle\Scheduler\Trigger\CronExpressionTrigger
 */
final class CronExpressionTriggerTest extends TestCase
{
    public function testValidCronExpression(): void
    {
        $trigger = new CronExpressionTrigger('* * * * *');

        $this->assertInstanceOf(CronExpressionTrigger::class, $trigger);
    }

    public function testInvalidCronExpressionThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cron expression');

        new CronExpressionTrigger('invalid cron');
    }

    /**
     * @dataProvider specialCronExpressionProvider
     */
    public function testSpecialCronExpressions(string $expression): void
    {
        $trigger = new CronExpressionTrigger($expression);

        $this->assertInstanceOf(CronExpressionTrigger::class, $trigger);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function specialCronExpressionProvider(): array
    {
        return [
            '@yearly' => ['@yearly'],
            '@annually' => ['@annually'],
            '@monthly' => ['@monthly'],
            '@weekly' => ['@weekly'],
            '@daily' => ['@daily'],
            '@midnight' => ['@midnight'],
            '@hourly' => ['@hourly'],
        ];
    }

    public function testGetNextRunDateReturnsFutureDate(): void
    {
        $trigger = new CronExpressionTrigger('0 0 * * *');
        $now = new \DateTimeImmutable('2024-01-15 12:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertInstanceOf(\DateTimeImmutable::class, $nextRun);
        $this->assertGreaterThan($now, $nextRun);
    }

    public function testToStringReturnsExpression(): void
    {
        $trigger = new CronExpressionTrigger('0 0 * * *');

        $this->assertSame('0 0 * * *', (string) $trigger);
    }

    public function testNextRunDateCalculation(): void
    {
        $trigger = new CronExpressionTrigger('0 12 * * *');
        $now = new \DateTimeImmutable('2024-01-15 10:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertSame('2024-01-15 12:00:00', $nextRun->format('Y-m-d H:i:s'));
    }

    public function testNextRunDateWhenAfterScheduledTime(): void
    {
        $trigger = new CronExpressionTrigger('0 10 * * *');
        $now = new \DateTimeImmutable('2024-01-15 15:00:00');

        $nextRun = $trigger->getNextRunDate($now);

        $this->assertSame('2024-01-16 10:00:00', $nextRun->format('Y-m-d H:i:s'));
    }
}
