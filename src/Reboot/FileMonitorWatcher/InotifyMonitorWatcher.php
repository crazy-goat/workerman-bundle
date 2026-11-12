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
    /** @var array<string, true> */
    private array $loggedWatchFailures = [];
    private \Closure|null $reloadCallback = null;

    public function start(): void
    {
        if (function_exists('inotify_init') && Worker::$globalEvent instanceof EventInterface) {
            try {
                $fd = @\inotify_init();
            } catch (\ErrorException) {
                $fd = false;
            }
            if ($fd === false) {
                try {
                    @Worker::log(
                        'InotifyMonitorWatcher: inotify_init() failed, files are not watched; '
                        . 'check /proc/sys/fs/inotify/max_user_instances and the open files limit',
                    );
                } catch (\ErrorException) {
                }

                return;
            }

            $this->fd = $fd;
            stream_set_blocking($this->fd, false);

            foreach ($this->sourceDir as $dir) {
                $this->watchDir($dir);
            }

            Worker::$globalEvent->delay(self::DEFERRED_WALK_DELAY, $this->deferredWalk(...));

            Worker::$globalEvent->onReadable($this->fd, $this->onNotify(...));
        }
    }

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
                    unset($this->loggedWatchFailures[$path]);
                }
                continue;
            }

            if ($this->isFlagSet($event['mask'], IN_MOVED_FROM | IN_ISDIR)) {
                $parentPath = $this->pathByWd[$event['wd']] ?? null;
                if ($parentPath !== null) {
                    $this->forgetWatchedTree($parentPath . '/' . $event['name']);
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
            return;
        }

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                $this->watchDir($file->getPathname());
            }
        }
    }

    private function forgetWatchedTree(string $path): void
    {
        unset($this->watchedPaths[$path], $this->loggedWatchFailures[$path]);
        foreach ($this->pathByWd as $wd => $watchedPath) {
            if ($watchedPath === $path || str_starts_with($watchedPath, $path . '/')) {
                unset($this->pathByWd[$wd], $this->watchedPaths[$watchedPath], $this->loggedWatchFailures[$watchedPath]);
            }
        }
    }

    private function watchDir(string $path): bool
    {
        if (isset($this->watchedPaths[$path])) {
            return true;
        }

        $wd = @\inotify_add_watch($this->fd, $path, IN_MODIFY | IN_CREATE | IN_DELETE | IN_MOVED_TO | IN_MOVED_FROM);

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
