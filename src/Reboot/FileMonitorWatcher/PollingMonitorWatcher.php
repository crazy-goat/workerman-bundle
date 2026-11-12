<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher;

use Workerman\Worker;

class PollingMonitorWatcher extends FileMonitorWatcher
{
    private int $lastMTime;

    /**
     * @var array<int, \RecursiveIteratorIterator<\RecursiveDirectoryIterator>>
     */
    private array $iterators = [];

    /**
     * @param string[] $sourceDir
     * @param string[] $filePattern
     */
    public function __construct(
        Worker $worker,
        array $sourceDir,
        array $filePattern,
        private readonly int $pollingInterval = self::DEFAULT_POLLING_INTERVAL,
        private readonly int $maxFilesPerTick = self::DEFAULT_MAX_FILES_PER_TICK,
    ) {
        if ($pollingInterval < 1 || $maxFilesPerTick < 1) {
            throw new \InvalidArgumentException(sprintf(
                'Polling interval and max files per tick must be >= 1, got %d and %d.',
                $pollingInterval,
                $maxFilesPerTick,
            ));
        }

        parent::__construct($worker, $sourceDir, $filePattern);
    }

    public function start(): void
    {
        $this->lastMTime = time();
        $this->worker::$globalEvent?->repeat($this->pollingInterval, $this->checkFileSystemChanges(...));
        $this->worker->log($this->worker->name . ' Polling file monitoring started with interval ' . $this->pollingInterval . 's, max ' . $this->maxFilesPerTick . ' files/tick.');
    }

    private function checkFileSystemChanges(): void
    {
        $filesProcessed = 0;

        foreach ($this->sourceDir as $dirIdx => $dir) {
            $iterator = $this->iterators[$dirIdx] ?? null;
            if ($iterator === null) {
                $iterator = $this->createRecursiveIterator(
                    $dir,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS,
                    \RecursiveIteratorIterator::LEAVES_ONLY,
                );
                $iterator->rewind();
                $this->iterators[$dirIdx] = $iterator;
            }

            try {
                while ($iterator->valid()) {
                    $filesProcessed++;
                    if ($filesProcessed > $this->maxFilesPerTick) {
                        return;
                    }

                    /** @var \SplFileInfo $file */
                    $file = $iterator->current();

                    if ($this->checkPattern($file->getFilename())) {
                        try {
                            $mtime = $file->getMTime();
                        } catch (\RuntimeException) {
                            $iterator->next();

                            continue;
                        }
                        if ($mtime > $this->lastMTime) {
                            $this->lastMTime = $mtime;
                            $this->resetSweep();

                            $this->reload();

                            return;
                        }
                    }

                    $iterator->next();
                }
            } catch (\UnexpectedValueException) {
                unset($this->iterators[$dirIdx]);

                continue;
            }

            unset($this->iterators[$dirIdx]);
        }
    }

    private function resetSweep(): void
    {
        $this->iterators = [];
    }
}
