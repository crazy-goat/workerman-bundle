<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Runner;

final readonly class StuckChildRunner extends Runner
{
    protected function fork(): int
    {
        $pid = \pcntl_fork();
        if ($pid === 0) {
            \sleep(10);
            \posix_kill((int) \getmypid(), \SIGKILL);
            exit(0);
        }
        return $pid;
    }
}
