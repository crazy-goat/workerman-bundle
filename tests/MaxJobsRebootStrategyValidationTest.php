<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Reboot\Strategy\MaxJobsRebootStrategy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CrazyGoat\WorkermanBundle\Reboot\Strategy\MaxJobsRebootStrategy
 */
final class MaxJobsRebootStrategyValidationTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function provideInvalidArguments(): iterable
    {
        yield 'zero requests' => [0, 0];
        yield 'negative requests' => [-5, 0];
        // Issue #1123 repro: negative dispersion used to reach
        // Randomizer::getInt(120, 100) and fail with a bare ValueError.
        yield 'negative dispersion' => [100, -20];
        yield 'dispersion above 100' => [100, 101];
    }

    /**
     * @dataProvider provideInvalidArguments
     */
    public function testConstructorRejectsOutOfRangeArguments(int $maxJobs, int $dispersion): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MaxJobsRebootStrategy($maxJobs, $dispersion);
    }

    public function testConstructorRejectsNegativeDispersionWithClearMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dispersion must be between 0 and 100, got -20');

        new MaxJobsRebootStrategy(100, -20);
    }

    public function testConstructorRejectsZeroRequestsWithClearMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxJobs must be at least 1, got 0');

        new MaxJobsRebootStrategy(0, 0);
    }

    public function testConstructorAcceptsFullDispersionBoundary(): void
    {
        // Dispersion 100 derives a minimum of zero (100 - 100% of 100),
        // which is a valid Randomizer range, so construction must succeed
        // and the worker must reboot within the requested job count.
        $strategy = new MaxJobsRebootStrategy(100, 100);

        $rebooted = false;
        for ($i = 0; $i < 100; ++$i) {
            if ($strategy->shouldReboot()) {
                $rebooted = true;
                break;
            }
        }

        self::assertTrue($rebooted, 'Should reboot within 100 jobs at 100% dispersion');
    }

    public function testConstructorAcceptsSingleRequestBoundary(): void
    {
        $strategy = new MaxJobsRebootStrategy(1, 0);

        self::assertTrue($strategy->shouldReboot(), 'Should reboot on the first job with requests 1');
    }
}
