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
                    $path = $file->getPathname();
                    if (!isset($this->watchedPaths[$path])) {
                        $this->watchDir($path);
                    }
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

        if ($this->reloadCallback instanceof \Closure) {
            return;
        }

        foreach ($events as $event) {
            if ($this->isFlagSet($event['mask'], IN_IGNORED)) {
                $path = $this->pathByWd[$event['wd']] ?? null;
                unset($this->pathByWd[$event['wd']]);
                if ($path !== null) {
                    unset($this->watchedPaths[$path]);
                }
                continue;
            }

            if ($this->isFlagSet($event['mask'], IN_CREATE | IN_ISDIR)) {
                $this->watchDir($this->pathByWd[$event['wd']] . '/' . $event['name']);
                continue;
            }

            if (!$this->checkPattern($event['name'])) {
                continue;
            }

            $this->reloadCallback = function (): void {
                $this->reloadCallback = null;
                $this->reload();
            };

            $this->worker::$globalEvent?->delay(self::RELOAD_DELAY, $this->reloadCallback);

            return;
        }
    }

    private function watchDir(string $path): void
    {
        $wd = \inotify_add_watch($this->fd, $path, IN_MODIFY | IN_CREATE | IN_DELETE | IN_MOVED_TO);
        $this->pathByWd[$wd] = $path;
        $this->watchedPaths[$path] = true;
    }

    private function isFlagSet(int $check, int $flag): bool
    {
        return ($check & $flag) === $flag;
    }
}
