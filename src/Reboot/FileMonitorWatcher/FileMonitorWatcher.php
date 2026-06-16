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

    /**
     * Create a recursive directory iterator with the given flags and mode.
     *
     * Both PollingMonitorWatcher and InotifyMonitorWatcher need this same
     * boilerplate; centralising it here means traversal behaviour (skip
     * dot-dirs, follow symlinks, etc.) can be changed in one place.
     *
     * @param int<0, max> $flags FilesystemIterator flags (e.g. SKIP_DOTS | UNIX_PATHS)
     * @param 0|1|2       $mode  RecursiveIteratorIterator mode (LEAVES_ONLY=0, SELF_FIRST=1, CHILD_FIRST=2)
     *
     * @return \RecursiveIteratorIterator<\RecursiveDirectoryIterator>
     */
    protected function createRecursiveIterator(string $dir, int $flags, int $mode): \RecursiveIteratorIterator
    {
        return new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, $flags),
            $mode,
        );
    }

    final protected function reload(): void
    {
        Utils::reload(reloadAllWorkers: true);
    }
}
