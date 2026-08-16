<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher;

class PollingMonitorWatcher extends FileMonitorWatcher
{
    private const POLLING_INTERVAL = 3;
    private const MAX_FILES_PER_TICK = 500;

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
     * Dir indexes whose sweep is still in progress (iterator not yet
     * exhausted).  When non-empty the next tick resumes from where the
     * previous tick stopped; when empty a fresh sweep begins.
     *
     * @var array<int, true>
     */
    private array $resumeDirs = [];

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
                    if ($filesProcessed > self::MAX_FILES_PER_TICK) {
                        $this->resumeDirs[$dirIdx] = true;

                        return;
                    }

                    /** @var \SplFileInfo $file */
                    $file = $iterator->current();

                    if ($this->checkPattern($file->getFilename())) {
                        $mtime = $file->getMTime();
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
                // descended into).  Discard this dir's iterator and start
                // fresh on the next tick instead of crashing the worker.
                unset($this->iterators[$dirIdx], $this->resumeDirs[$dirIdx]);

                continue;
            }

            // Iterator exhausted — this dir's sweep is complete.
            unset($this->iterators[$dirIdx], $this->resumeDirs[$dirIdx]);
        }
    }

    /**
     * Discard all persisted iterators and resume state so the next tick
     * starts a fresh sweep from the root of every source dir.
     */
    private function resetSweep(): void
    {
        $this->iterators = [];
        $this->resumeDirs = [];
    }
}
