<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Worker;

use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\FileMonitorWatcher;
use Workerman\Worker;

final class FileMonitorWorker
{
    public const PROCESS_TITLE = '[FileMonitor]';

    /**
     * @param string[] $sourceDir
     * @param string[] $filePattern
     */
    public function __construct(?string $user, ?string $group, array $sourceDir, array $filePattern)
    {
        $worker = new Worker();
        $worker->name = self::PROCESS_TITLE;
        $worker->user = $user ?? '';
        $worker->group = $group ?? '';
        $worker->count = 1;
        $worker->reloadable = false;
        $worker->onWorkerStart = function (Worker $worker) use ($sourceDir, $filePattern): void {
            $worker->log($worker->name . ' started');
            $fileMonitor = FileMonitorWatcher::create($worker, $sourceDir, $filePattern);
            $fileMonitor->start();
        };
    }
}
