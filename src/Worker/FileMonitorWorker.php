<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Worker;

use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\FileMonitorWatcher;
use Workerman\Worker;

final readonly class FileMonitorWorker
{
    private const PROCESS_TITLE = '[FileMonitor]';

    /**
     * @param string[] $sourceDir
     * @param string[] $filePattern
     */
    public function __construct(
        ?string $user,
        ?string $group,
        array $sourceDir,
        array $filePattern,
        int $pollingInterval = FileMonitorWatcher::DEFAULT_POLLING_INTERVAL,
        int $maxFilesPerTick = FileMonitorWatcher::DEFAULT_MAX_FILES_PER_TICK,
    ) {
        $worker = new Worker();
        $worker->name = self::PROCESS_TITLE;
        $worker->user = $user ?? '';
        $worker->group = $group ?? '';
        $worker->count = 1;
        $worker->reloadable = false;
        $worker->onWorkerStart = function (Worker $worker) use ($sourceDir, $filePattern, $pollingInterval, $maxFilesPerTick): void {
            $worker->log($worker->name . ' started');
            $fileMonitor = FileMonitorWatcher::create($worker, $sourceDir, $filePattern, $pollingInterval, $maxFilesPerTick);
            $fileMonitor->start();
        };
    }
}
