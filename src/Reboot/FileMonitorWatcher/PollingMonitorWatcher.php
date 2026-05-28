<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher;

final class PollingMonitorWatcher extends FileMonitorWatcher
{
    private const POLLING_INTERVAL = 3;
    private const MAX_FILES_PER_TICK = 500;

    private int $lastMTime;
    /** @var array<int, string> */
    private array $resumePaths = [];

    public function start(): void
    {
        $this->lastMTime = time();
        $this->worker::$globalEvent?->repeat(self::POLLING_INTERVAL, $this->checkFileSystemChanges(...));
        $this->worker->log($this->worker->name . ' Polling file monitoring started with interval ' . self::POLLING_INTERVAL . 's, max ' . self::MAX_FILES_PER_TICK . ' files/tick.');
    }

    private function checkFileSystemChanges(): void
    {
        $filesProcessed = 0;

        foreach ($this->sourceDir as $dirIdx => $dir) {
            $iterator = $this->createRecursiveIterator(
                $dir,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS,
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            $resumeFrom = $this->resumePaths[$dirIdx] ?? null;
            $resuming = ($resumeFrom !== null);

            foreach ($iterator as $file) {
                if ($resuming) {
                    if ($file->getPathname() !== $resumeFrom) {
                        continue;
                    }

                    $resuming = false;
                }

                $filesProcessed++;

                if ($filesProcessed > self::MAX_FILES_PER_TICK) {
                    $this->resumePaths[$dirIdx] = $file->getPathname();

                    return;
                }

                if (!$this->checkPattern($file->getFilename())) {
                    continue;
                }

                if ($file->getMTime() > $this->lastMTime) {
                    $this->lastMTime = $file->getMTime();
                    $this->resumePaths = [];

                    $this->reload();

                    return;
                }
            }

            unset($this->resumePaths[$dirIdx]);
        }
    }
}
