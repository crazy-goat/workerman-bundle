<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Benchmark;

use CrazyGoat\WorkermanBundle\Scheduler\Trigger\PeriodicalTrigger;
use PhpBench\Benchmark\Metadata\Annotations\BeforeMethods;
use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use PhpBench\Benchmark\Metadata\Annotations\Warmup;

/**
 * Benchmark PeriodicalTrigger::getNextRunDate — the scheduler hot path that
 * calculates the next execution time for periodic tasks.
 *
 * @BeforeMethods("init")
 * @Revs(1000)
 * @Iterations(5)
 * @Warmup(1)
 */
final class PeriodicalTriggerBench
{
    private PeriodicalTrigger $secondsTrigger;
    private PeriodicalTrigger $isoTrigger;
    private PeriodicalTrigger $stringTrigger;
    private \DateTimeImmutable $now;

    public function init(): void
    {
        $this->secondsTrigger = new PeriodicalTrigger(60);
        $this->isoTrigger = new PeriodicalTrigger('PT1H');
        $this->stringTrigger = new PeriodicalTrigger('+1 day');
        $this->now = new \DateTimeImmutable('2024-01-15 12:00:00');
    }

    public function benchSecondsInterval(): void
    {
        // @phpstan-ignore method.resultUnused
        $this->secondsTrigger->getNextRunDate($this->now);
    }

    public function benchIsoInterval(): void
    {
        // @phpstan-ignore method.resultUnused
        $this->isoTrigger->getNextRunDate($this->now);
    }

    public function benchStringInterval(): void
    {
        // @phpstan-ignore method.resultUnused
        $this->stringTrigger->getNextRunDate($this->now);
    }
}
