<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher;

use CrazyGoat\WorkermanBundle\Utils;
use Workerman\Worker;

abstract class FileMonitorWatcher
{
    /** @var string[] */
    protected readonly array $sourceDir;

    /**
     * @param string[] $sourceDir
     * @param string[] $filePattern
     */
    public static function create(Worker $worker, array $sourceDir, array $filePattern): self
    {
        return \extension_loaded('inotify')
            ? new InotifyMonitorWatcher($worker, $sourceDir, $filePattern)
            : new PollingMonitorWatcher($worker, $sourceDir, $filePattern)
        ;
    }

    /**
     * @param string[] $sourceDir
     * @param string[] $filePattern
     */
    protected function __construct(protected readonly Worker $worker, array $sourceDir, private readonly array $filePattern)
    {
        $this->sourceDir = array_filter($sourceDir, is_dir(...));
    }

    abstract public function start(): void;

    final protected function checkPattern(string $filename): bool
    {
        foreach ($this->filePattern as $pattern) {
            if (\fnmatch($pattern, $filename)) {
                return true;
            }
        }

        return false;
    }

    final protected function reboot(): void
    {
        Utils::reboot(rebootAllWorkers: true);
    }
}
