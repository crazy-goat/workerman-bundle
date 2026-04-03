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
 * Tests for TriggerFactory.
 *
 * @covers \CrazyGoat\WorkermanBundle\Scheduler\Trigger\TriggerFactory
 */
final class TriggerFactoryTest extends TestCase
{
    /**
     * Test creating trigger from integer (seconds).
     */
    public function testCreateFromInteger(): void
    {
        $trigger = TriggerFactory::create(60);

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
    }

    /**
     * Test creating trigger from ISO8601 datetime string.
     */
    public function testCreateFromIso8601DateTime(): void
    {
        $trigger = TriggerFactory::create('2024-12-25T10:00:00+00:00');

        $this->assertInstanceOf(DateTimeTrigger::class, $trigger);
    }

    /**
     * Test creating trigger from cron expression with 5 parts.
     */
    public function testCreateFromCronExpressionFiveParts(): void
    {
        $trigger = TriggerFactory::create('* * * * *');

        $this->assertInstanceOf(CronExpressionTrigger::class, $trigger);
    }

    /**
     * Test creating trigger from cron expression with @ prefix.
     */
    public function testCreateFromCronExpressionWithAtPrefix(): void
    {
        $trigger = TriggerFactory::create('@daily');

        $this->assertInstanceOf(CronExpressionTrigger::class, $trigger);
    }

    /**
     * Test creating trigger from ISO8601 duration string.
     */
    public function testCreateFromIso8601Duration(): void
    {
        $trigger = TriggerFactory::create('PT1H');

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
    }

    /**
     * Test creating trigger from relative date string.
     */
    public function testCreateFromRelativeDateString(): void
    {
        $trigger = TriggerFactory::create('+1 hour');

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
    }

    /**
     * Test creating trigger from DateInterval.
     */
    public function testCreateFromDateInterval(): void
    {
        $interval = new \DateInterval('PT30M');
        $trigger = TriggerFactory::create($interval);

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
    }

    /**
     * Test creating trigger from DateTimeImmutable.
     */
    public function testCreateFromDateTimeImmutable(): void
    {
        $dateTime = new \DateTimeImmutable('2024-12-25 10:00:00');
        $trigger = TriggerFactory::create($dateTime);

        $this->assertInstanceOf(DateTimeTrigger::class, $trigger);
    }

    /**
     * Test creating trigger with jitter.
     */
    public function testCreateWithJitter(): void
    {
        $trigger = TriggerFactory::create(60, 5);

        $this->assertInstanceOf(JitterTrigger::class, $trigger);
    }

    /**
     * Test creating trigger without jitter returns base trigger.
     */
    public function testCreateWithoutJitterReturnsBaseTrigger(): void
    {
        $trigger = TriggerFactory::create(60, 0);

        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
        $this->assertNotInstanceOf(JitterTrigger::class, $trigger);
    }

    /**
     * Test various cron expression formats are detected correctly.
     *
     * @dataProvider cronExpressionProvider
     */
    public function testCronExpressionDetection(string $expression): void
    {
        $trigger = TriggerFactory::create($expression);

        $this->assertInstanceOf(
            CronExpressionTrigger::class,
            $trigger,
            "Expression '{$expression}' should be detected as cron expression"
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

    /**
     * Test that non-cron expressions with 5 space-separated parts are handled.
     *
     * This tests the heuristic mentioned in ticket #34.
     * Currently "1 2 3 4 5" has 5 parts but no asterisks, so it's treated as PeriodicalTrigger.
     * However, it will fail because it's not a valid interval format.
     */
    public function testFivePartNonCronExpressionThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid interval');

        // This is a tricky case - "1 2 3 4 5" has 5 parts but no asterisks
        // Current implementation treats it as PeriodicalTrigger which then fails
        TriggerFactory::create('1 2 3 4 5');
    }

    /**
     * Test that expressions with asterisks but not 5 parts are handled.
     *
     * Currently "* * * *" has 4 parts with asterisks but fails as invalid interval.
     */
    public function testExpressionWithAsterisksButNotFivePartsThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid interval');

        // "* * * *" has 4 parts with asterisks but is not a valid interval
        TriggerFactory::create('* * * *');
    }
}
