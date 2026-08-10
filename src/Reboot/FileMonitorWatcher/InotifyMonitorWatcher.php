<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher;

use Workerman\Events\EventInterface;
use Workerman\Worker;

final class InotifyMonitorWatcher extends FileMonitorWatcher
{
    private const RELOAD_DELAY = 0.33;
    private const DEFERRED_WALK_DELAY = 0.01;
    /** @var resource */
    private $fd;
    /** @var array<int, string> */
    private array $pathByWd = [];
    /** @var array<string, true> */
    private array $watchedPaths = [];
    /** @var array<string, true> Paths whose failed add_watch was already reported */
    private array $loggedWatchFailures = [];
    private \Closure|null $reloadCallback = null;

    public function start(): void
    {
        if (function_exists('inotify_init') && Worker::$globalEvent instanceof EventInterface) {
            $this->fd = \inotify_init();
            stream_set_blocking($this->fd, false);

            // Phase 1: Watch only top-level directories for instant startup.
            // Deep subdirectories are watched lazily after the event loop runs.
            foreach ($this->sourceDir as $dir) {
                $this->watchDir($dir);
            }

            // Phase 2: Schedule a deferred recursive walk so existing subdirectories
            // are still watched, but without blocking the event loop at boot.
            Worker::$globalEvent->delay(self::DEFERRED_WALK_DELAY, $this->deferredWalk(...));

            Worker::$globalEvent->onReadable($this->fd, $this->onNotify(...));
        }
    }

    /**
     * Deferred recursive walk that watches all existing subdirectories.
     *
     * Called once after the event loop starts. This avoids a synchronous
     * full-directory walk at boot, which can be slow on very large source trees.
     */
    private function deferredWalk(): void
    {
        foreach ($this->sourceDir as $dir) {
            $iterator = $this->createRecursiveIterator(
                $dir,
                \FilesystemIterator::SKIP_DOTS,
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isDir()) {
                    $this->watchDir($file->getPathname());
                }
            }
        }
    }

    /**
     * @param resource $inotifyFd
     */
    private function onNotify(mixed $inotifyFd): void
    {
        $events = \inotify_read($inotifyFd) ?: [];

        foreach ($events as $event) {
            if ($this->isFlagSet($event['mask'], IN_IGNORED)) {
                $path = $this->pathByWd[$event['wd']] ?? null;
                unset($this->pathByWd[$event['wd']]);
                if ($path !== null) {
                    unset($this->watchedPaths[$path]);
                }
                continue;
            }

            if (
                $this->isFlagSet($event['mask'], IN_CREATE | IN_ISDIR)
                || $this->isFlagSet($event['mask'], IN_MOVED_TO | IN_ISDIR)
            ) {
                $parentPath = $this->pathByWd[$event['wd']] ?? null;
                if ($parentPath !== null) {
                    $this->watchDirTree($parentPath . '/' . $event['name']);
                }
                continue;
            }

            if (!$this->checkPattern($event['name'])) {
                continue;
            }

            // Bookkeeping above always runs, even while a reload is pending;
            // only the reload scheduling is skipped once one is already armed.
            if ($this->reloadCallback instanceof \Closure) {
                continue;
            }

            $this->reloadCallback = function (): void {
                $this->reloadCallback = null;
                $this->reload();
            };

            $this->worker::$globalEvent?->delay(self::RELOAD_DELAY, $this->reloadCallback);
        }
    }

    /**
     * Watch a directory and any subdirectories it already contains.
     *
     * A directory moved into the tree (or created and populated in one step)
     * can arrive with children already present; those children never produce
     * events because no watch existed when they were created, so their
     * watches have to be established by walking the subtree.
     */
    private function watchDirTree(string $path): void
    {
        if (!$this->watchDir($path)) {
            return;
        }

        try {
            $iterator = $this->createRecursiveIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS,
                \RecursiveIteratorIterator::SELF_FIRST,
            );
        } catch (\UnexpectedValueException) {
            // Directory disappeared between the event and this walk.
            return;
        }

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                $this->watchDir($file->getPathname());
            }
        }
    }

    /**
     * Add a watch for one directory. Idempotent per path; safe to call from
     * every entry point (start, deferred walk, event processing).
     */
    private function watchDir(string $path): bool
    {
        if (isset($this->watchedPaths[$path])) {
            return true;
        }

        $wd = \inotify_add_watch($this->fd, $path, IN_MODIFY | IN_CREATE | IN_DELETE | IN_MOVED_TO);

        if ($wd === false) {
            if (!isset($this->loggedWatchFailures[$path])) {
                $this->loggedWatchFailures[$path] = true;
                Worker::log(sprintf(
                    'InotifyMonitorWatcher: failed to watch "%s" (inotify_add_watch() returned false); '
                    . 'check /proc/sys/fs/inotify/max_user_watches if many directories are unwatched',
                    $path,
                ));
            }

            return false;
        }

        // A watch surviving a move keeps its descriptor, so the descriptor may
        // already be mapped to the old location of this directory: drop that
        // stale path to keep both maps consistent.
        $previousPath = $this->pathByWd[$wd] ?? null;
        if ($previousPath !== null && $previousPath !== $path) {
            unset($this->watchedPaths[$previousPath]);
        }

        $this->pathByWd[$wd] = $path;
        $this->watchedPaths[$path] = true;

        return true;
    }

    private function isFlagSet(int $check, int $flag): bool
    {
        return ($check & $flag) === $flag;
    }
}
