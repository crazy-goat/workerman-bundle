<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\Strategy;

final class MaxJobsRebootStrategy implements RebootStrategyInterface
{
    private int $jobsCount = 0;
    private readonly int $maxJobs;

    public function __construct(
        int $maxJobs,
        int $dispersion = 0,
        private readonly \Random\Randomizer $randomizer = new \Random\Randomizer(),
    ) {
        $minJobs = $maxJobs - (int) round($maxJobs * $dispersion / 100);
        $this->maxJobs = $this->randomizer->getInt($minJobs, $maxJobs);
    }

    public function shouldReboot(): bool
    {
        return ++$this->jobsCount > $this->maxJobs;
    }
}
