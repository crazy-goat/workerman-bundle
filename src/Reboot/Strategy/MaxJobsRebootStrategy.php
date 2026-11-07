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
        if ($maxJobs < 1) {
            throw new \InvalidArgumentException(sprintf('maxJobs must be at least 1, got %d.', $maxJobs));
        }
        if ($dispersion < 0 || $dispersion > 100) {
            throw new \InvalidArgumentException(sprintf('dispersion must be between 0 and 100, got %d.', $dispersion));
        }
        $minJobs = $maxJobs - (int) round($maxJobs * $dispersion / 100);
        $this->maxJobs = $this->randomizer->getInt($minJobs, $maxJobs);
    }

    public function shouldReboot(): bool
    {
        // shouldReboot() is consulted after each request is handled, so
        // the Nth request must trigger the reload (issue #967): with `>`
        // the worker served maxJobs + 1 requests before reloading.
        return ++$this->jobsCount >= $this->maxJobs;
    }
}
