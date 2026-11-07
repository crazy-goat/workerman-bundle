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
     * Position of the source dir the next tick starts at.
     *
     * A tick that runs out of budget parks the iterator of the dir it
     * stopped in; the next tick resumes at that dir instead of
     * restarting at the first dir. Without this, a first dir that fills
     * the budget on its own is re-scanned on every tick and later dirs
     * starve — their parked iterators never get another turn.
     *
     * This is a position into the source-dir list, not a dir key: the
     * parent constructor filters out non-existent dirs, so the keys of
     * $sourceDir may not be sequential.
     */
    private int $sweepStart = 0;

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
        $dirKeys = \array_keys($this->sourceDir);
        $dirCount = \count($dirKeys);

        // Visit the dirs in rotated order, starting at the dir the
        // previous tick stopped in, so a later dir is reached even when
        // an earlier dir fills the per-tick budget on its own.
        for ($offset = 0; $offset < $dirCount; $offset++) {
            $dirIdx = $dirKeys[($this->sweepStart + $offset) % $dirCount];
            $dir = $this->sourceDir[$dirIdx];
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
                        // Park the sweep here: the next tick resumes at
                        // this dir instead of re-scanning earlier dirs.
                        $this->sweepStart = ($this->sweepStart + $offset) % $dirCount;

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

        // Every dir was visited: the sweep is complete, so the next tick
        // starts a fresh sweep at the first dir again.
        $this->sweepStart = 0;
    }

    /**
     * Discard all persisted iterators so the next tick starts a fresh
     * sweep from the root of every source dir.
     */
    private function resetSweep(): void
    {
        $this->iterators = [];
        $this->sweepStart = 0;
    }
}
