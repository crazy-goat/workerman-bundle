<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher;

use Workerman\Worker;

class PollingMonitorWatcher extends FileMonitorWatcher
{
    private int $lastMTime;

    /**
     * Persisted iterators, one per source-dir index.
     *
     * An entry is created lazily on the first tick that visits the dir and
     * survives across ticks so the tree is walked exactly once per sweep
     * (O(N) total advances) instead of being re-traversed from the root
     * every tick (O(N²/budget)).
     *
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
                    // Every entry counts against the budget — including the
                    // first one after a resume — so the tick bound is real.
                    $filesProcessed++;
                    if ($filesProcessed > $this->maxFilesPerTick) {
                        return;
                    }

                    /** @var \SplFileInfo $file */
                    $file = $iterator->current();

                    if ($this->checkPattern($file->getFilename())) {
                        // The iterator is held across ticks (polling interval
                        // seconds), so the file at current() may have been
                        // deleted between ticks.  A deleted file is not a
                        // modification — skip it.  SplFileInfo::getMTime()
                        // on a missing path throws \RuntimeException
                        // ("stat failed"), which the outer catch below does
                        // not cover (it only handles directory removal).
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
                // A directory was removed mid-sweep (e.g. between ticks the
                // operator deleted a subdir the iterator had not yet
                // descended into, or a subtree the iterator was about to
                // open).  Discard this dir's iterator and start fresh on
                // the next tick instead of crashing the worker.
                unset($this->iterators[$dirIdx]);

                continue;
            }

            // Iterator exhausted — this dir's sweep is complete.
            unset($this->iterators[$dirIdx]);
        }
    }

    /**
     * Discard all persisted iterators so the next tick starts a fresh
     * sweep from the root of every source dir.
     */
    private function resetSweep(): void
    {
        $this->iterators = [];
    }
}
