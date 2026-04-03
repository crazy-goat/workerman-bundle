<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Scheduler\Trigger\CronExpressionTrigger;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\DateTimeTrigger;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\JitterTrigger;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\PeriodicalTrigger;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\TriggerFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CrazyGoat\WorkermanBundle\Scheduler\Trigger\TriggerFactory
 */
final class TriggerFactoryTest extends TestCase
{
    public function testCreateFromInteger(): void
    {
        $trigger = TriggerFactory::create(60);

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
    }

    public function testCreateFromIso8601DateTime(): void
    {
        $trigger = TriggerFactory::create('2024-12-25T10:00:00+00:00');

        $this->assertInstanceOf(DateTimeTrigger::class, $trigger);

        // Verify the trigger's date is correctly parsed
        $now = new \DateTimeImmutable('2024-12-20 12:00:00');
        $nextRun = $trigger->getNextRunDate($now);
        $this->assertSame('2024-12-25 10:00:00', $nextRun->format('Y-m-d H:i:s'));
    }

    public function testCreateFromCronExpressionFiveParts(): void
    {
        $trigger = TriggerFactory::create('* * * * *');

        $this->assertInstanceOf(CronExpressionTrigger::class, $trigger);
    }

    public function testCreateFromCronExpressionWithAtPrefix(): void
    {
        $trigger = TriggerFactory::create('@daily');

        $this->assertInstanceOf(CronExpressionTrigger::class, $trigger);
    }

    public function testCreateFromIso8601Duration(): void
    {
        $trigger = TriggerFactory::create('PT1H');

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
    }

    public function testCreateFromRelativeDateString(): void
    {
        $trigger = TriggerFactory::create('+1 hour');

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
    }

    public function testCreateFromDateInterval(): void
    {
        $interval = new \DateInterval('PT30M');
        $trigger = TriggerFactory::create($interval);

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
    }

    public function testCreateFromDateTimeImmutable(): void
    {
        $dateTime = new \DateTimeImmutable('2024-12-25 10:00:00');
        $trigger = TriggerFactory::create($dateTime);

        $this->assertInstanceOf(DateTimeTrigger::class, $trigger);
    }

    public function testCreateWithJitter(): void
    {
        $trigger = TriggerFactory::create(60, 5);

        $this->assertInstanceOf(JitterTrigger::class, $trigger);
    }

    public function testCreateWithoutJitterReturnsBaseTrigger(): void
    {
        $trigger = TriggerFactory::create(60, 0);

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
        $this->assertNotInstanceOf(JitterTrigger::class, $trigger);
    }

    /**
     * @dataProvider cronExpressionProvider
     */
    public function testCronExpressionDetection(string $expression): void
    {
        $trigger = TriggerFactory::create($expression);

        $this->assertInstanceOf(
            CronExpressionTrigger::class,
            $trigger,
            "Expression '{$expression}' should be detected as cron expression",
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cronExpressionProvider(): array
    {
        return [
            'every minute' => ['* * * * *'],
            'every hour' => ['0 * * * *'],
            'daily at midnight' => ['0 0 * * *'],
            'weekly' => ['0 0 * * 0'],
            'monthly' => ['0 0 1 * *'],
            'complex' => ['*/5 9-17 * * 1-5'],
            'with @yearly' => ['@yearly'],
            'with @monthly' => ['@monthly'],
            'with @weekly' => ['@weekly'],
            'with @daily' => ['@daily'],
            'with @hourly' => ['@hourly'],
        ];
    }

    public function testFivePartNonCronExpressionThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid interval');

        TriggerFactory::create('1 2 3 4 5');
    }

    public function testExpressionWithAsterisksButNotFivePartsThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid interval');

        TriggerFactory::create('* * * *');
    }
}
