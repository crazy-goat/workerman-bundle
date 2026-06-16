<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Fixtures\PollingMonitorWatcher;

use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\PollingMonitorWatcher;

/**
 * PollingMonitorWatcher subclass that uses CountingRecursiveDirectoryIterator,
 * so stat() calls performed by the watcher can be observed and counted.
 */
final class CountingPollingMonitorWatcher extends PollingMonitorWatcher
{
    /**
     * @param int<0, max> $flags
     * @param 0|1|2       $mode
     */
    protected function createRecursiveIterator(string $dir, int $flags, int $mode): \RecursiveIteratorIterator
    {
        // @phpstan-ignore-next-line return.type — CountingRecursiveDirectoryIterator extends \RecursiveDirectoryIterator
        return new \RecursiveIteratorIterator(
            new CountingRecursiveDirectoryIterator($dir, $flags),
            $mode,
        );
    }
}
