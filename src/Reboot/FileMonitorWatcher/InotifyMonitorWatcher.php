<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher;

use Workerman\Events\EventInterface;
use Workerman\Worker;

final class InotifyMonitorWatcher extends FileMonitorWatcher
{
    private const RELOAD_DELAY = 0.33;
    /** @var resource */
    private $fd;
    /** @var string[] */
    private array $pathByWd = [];
    private \Closure|null $reloadCallback = null;

    public function start(): void
    {
        if (function_exists('inotify_init') && Worker::$globalEvent instanceof EventInterface) {
            $this->fd = \inotify_init();
            stream_set_blocking($this->fd, false);

            foreach ($this->sourceDir as $dir) {
                $iterator = $this->createRecursiveIterator(
                    $dir,
                    \FilesystemIterator::SKIP_DOTS,
                    \RecursiveIteratorIterator::SELF_FIRST,
                );

                $this->watchDir($dir);

                foreach ($iterator as $file) {
                    /** @var \SplFileInfo $file */
                    if ($file->isDir()) {
                        $this->watchDir($file->getPathname());
                    }
                }
            }

            Worker::$globalEvent->onReadable($this->fd, $this->onNotify(...));
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
                unset($this->pathByWd[$event['wd']]);
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
    }

    private function isFlagSet(int $check, int $flag): bool
    {
        return ($check & $flag) === $flag;
    }
}
