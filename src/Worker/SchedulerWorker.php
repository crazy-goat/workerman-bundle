<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Worker;

use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Scheduler\TaskHandler;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\TriggerFactory;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\TriggerInterface;
use CrazyGoat\WorkermanBundle\Util\ServiceMethod;
use Workerman\Worker;

final class SchedulerWorker
{
    private const PROCESS_TITLE = '[Scheduler]';

    private readonly Worker $worker;

    /** @var array<string, resource> */
    private array $pidFileHandles = [];

    /**
     * @param mixed[] $schedulerConfig
     */
    public function __construct(KernelFactory $kernelFactory, ?string $user, ?string $group, array $schedulerConfig)
    {
        $this->worker = new Worker();
        $this->worker->name = self::PROCESS_TITLE;
        $this->worker->user = $user ?? '';
        $this->worker->group = $group ?? '';
        $this->worker->count = 1;
        $this->worker->reloadable = true;
        $this->worker->onWorkerStart = function () use ($kernelFactory, $schedulerConfig): void {
            $this->worker->log($this->worker->name . ' started');

            pcntl_signal(SIGCHLD, $this->handleSigchld(...));

            $kernel = $kernelFactory->createKernel();
            $kernel->boot();
            $handler = $kernel->getContainer()->get('workerman.task_handler');
            assert($handler instanceof TaskHandler);

            foreach ($schedulerConfig as $serviceId => $serviceConfig) {
                assert(is_array($serviceConfig));
                $taskName = empty($serviceConfig['name']) ? $serviceId : $serviceConfig['name'];

                if (empty($serviceConfig['schedule'])) {
                    $this->worker->log(sprintf('%s Task "%s" skipped. Trigger has not been set', $this->worker->name, $taskName));
                    continue;
                }

                try {
                    $trigger = TriggerFactory::create($serviceConfig['schedule'], $serviceConfig['jitter'] ?? 0);
                } catch (\InvalidArgumentException) {
                    $this->worker->log(sprintf('%s Task "%s" skipped. Trigger "%s" is incorrect', $this->worker->name, $taskName, $serviceConfig['schedule']));
                    continue;
                }

                $this->worker->log(sprintf('%s Task "%s" scheduled. Trigger: "%s"', $this->worker->name, $taskName, $trigger));
                $method = empty($serviceConfig['method']) ? '__invoke' : $serviceConfig['method'];
                $service = new ServiceMethod($serviceId, $method);
                $this->deleteTaskPid($service);
                $this->scheduleCallback($trigger, $service, $taskName, $handler);
            }
        };
    }

    /**
     * Reap terminated child processes and log non-zero exits or signal kills.
     */
    private function handleSigchld(): void
    {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            if (pcntl_wifexited($status)) {
                $exitCode = pcntl_wexitstatus($status);
                if ($exitCode !== 0) {
                    $this->worker->log(
                        sprintf('%s [warning] Child process %d exited with code %d', $this->worker->name, $pid, $exitCode),
                    );
                }
            } elseif (pcntl_wifsignaled($status)) {
                $signal = pcntl_wtermsig($status);
                $this->worker->log(
                    sprintf('%s [warning] Child process %d was killed by signal %d', $this->worker->name, $pid, $signal),
                );
            }
        }
    }

    private function scheduleCallback(TriggerInterface $trigger, ServiceMethod $service, string $taskName, TaskHandler $handler): void
    {
        static $tickCallbacks = [];

        $currentDate = new \DateTimeImmutable();
        $nextRunDate = $trigger->getNextRunDate($currentDate);
        if ($nextRunDate instanceof \DateTimeImmutable) {
            $interval = $nextRunDate->getTimestamp() - $currentDate->getTimestamp();
            $key = $service->toString();
            if (!isset($tickCallbacks[$key])) {
                $tickCallbacks[$key] = [
                    $this->onTickTimer(...),
                    [$trigger, $service, $taskName, $handler],
                ];
            }
            $this->worker::$globalEvent?->delay($interval, $tickCallbacks[$key][0], $tickCallbacks[$key][1]);
        }
    }

    private function onTickTimer(TriggerInterface $trigger, ServiceMethod $service, string $taskName, TaskHandler $handler): void
    {
        $this->runCallback($trigger, $service, $taskName, $handler);
    }

    private function runCallback(TriggerInterface $trigger, ServiceMethod $service, string $taskName, TaskHandler $handler): void
    {
        $pidFile = $this->getTaskPidPath($service);
        $fp = $this->openPidFile($pidFile, $taskName);
        if ($fp === null) {
            $this->scheduleCallback($trigger, $service, $taskName, $handler);
            return;
        }

        if (!$this->acquireLock($fp)) {
            $this->scheduleCallback($trigger, $service, $taskName, $handler);
            return;
        }

        $pid = \pcntl_fork();
        if ($pid === -1) {
            $this->handleForkError($fp, $trigger, $service, $taskName, $handler);
        } elseif ($pid > 0) {
            $this->handleParent($fp, $trigger, $service, $taskName, $handler);
        } else {
            $this->handleChild($pidFile, $service, $taskName, $handler);
        }
    }

    /**
     * @return resource|null
     */
    private function openPidFile(string $pidFile, string $taskName): mixed
    {
        if (isset($this->pidFileHandles[$pidFile]) && is_resource($this->pidFileHandles[$pidFile])) {
            return $this->pidFileHandles[$pidFile];
        }

        if ($this->isPidFileSymlink($pidFile)) {
            $this->worker->log(sprintf('%s Task "%s" PID file is a symlink, rejecting: %s', $this->worker->name, $taskName, $pidFile));
            return null;
        }

        $fp = $this->tryOpenPidFile($pidFile, $taskName);
        if ($fp === null) {
            return null;
        }

        if ($this->isPidFileInodeMismatch($fp, $pidFile)) {
            fclose($fp);
            $this->worker->log(sprintf('%s Task "%s" PID file inode mismatch, rejecting: %s', $this->worker->name, $taskName, $pidFile));
            return null;
        }

        $this->pidFileHandles[$pidFile] = $fp;

        return $fp;
    }

    /**
     * @return resource|null
     */
    private function tryOpenPidFile(string $pidFile, string $taskName): mixed
    {
        $fp = @fopen($pidFile, 'c');
        if ($fp === false) {
            $this->worker->log(sprintf('%s Task "%s" cannot open PID file: %s', $this->worker->name, $taskName, $pidFile));

            return null;
        }

        return $fp;
    }

    private function isPidFileSymlink(string $pidFile): bool
    {
        clearstatcache(true, $pidFile);

        return is_link($pidFile);
    }

    private function isPidFileInodeMismatch(mixed $fp, string $pidFile): bool
    {
        $statFd = @fstat($fp);
        $statPath = @lstat($pidFile);
        if ($statFd === false || $statPath === false) {
            return true;
        }

        if ($statFd['ino'] !== $statPath['ino'] || $statFd['dev'] !== $statPath['dev']) {
            return true;
        }

        return $statFd['uid'] !== posix_getuid();
    }

    private function acquireLock(mixed $fp): bool
    {
        return flock($fp, LOCK_EX | LOCK_NB);
    }

    private function releaseLock(mixed $fp): void
    {
        flock($fp, LOCK_UN);
    }

    private function releaseLockAndClose(mixed $fp): void
    {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private function handleForkError(mixed $fp, TriggerInterface $trigger, ServiceMethod $service, string $taskName, TaskHandler $handler): void
    {
        $this->releaseLock($fp);
        $this->worker->log(sprintf('%s Task "%s" call error!', $this->worker->name, $taskName));
        $this->scheduleCallback($trigger, $service, $taskName, $handler);
    }

    private function handleParent(mixed $fp, TriggerInterface $trigger, ServiceMethod $service, string $taskName, TaskHandler $handler): void
    {
        $this->releaseLock($fp);
        $this->worker->log(sprintf('%s Task "%s" called', $this->worker->name, $taskName));
        $this->scheduleCallback($trigger, $service, $taskName, $handler);
    }

    private function handleChild(string $pidFile, ServiceMethod $service, string $taskName, TaskHandler $handler): void
    {
        $fp = $this->openChildPidFile($pidFile);
        if ($fp === null) {
            exit(0);
        }

        $this->worker::$globalEvent?->deleteAllTimer();
        $title = str_replace(self::PROCESS_TITLE, sprintf('%s %s', self::PROCESS_TITLE, $taskName), strval(cli_get_process_title()));
        cli_set_process_title($title);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, strval(posix_getpid()));
        fflush($fp);

        $childExitCode = 0;
        try {
            $handler($service, $taskName);
        } catch (\Throwable $e) {
            $this->worker->log(sprintf(
                '%s Task "%s" failed: [%s] %s in %s:%d\nStack trace:\n%s',
                $this->worker->name,
                $taskName,
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString(),
            ));
            $childExitCode = 1;
        } finally {
            $this->releaseLockAndClose($fp);
            $this->deleteTaskPid($service);
            exit($childExitCode);
        }
    }

    /**
     * @return resource|null
     */
    private function openChildPidFile(string $pidFile): mixed
    {
        if ($this->isPidFileSymlink($pidFile)) {
            return null;
        }

        $fp = @fopen($pidFile, 'c');
        if ($fp === false) {
            return null;
        }

        if ($this->isPidFileInodeMismatch($fp, $pidFile)) {
            fclose($fp);
            return null;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return null;
        }

        return $fp;
    }

    private function getTaskPidPath(ServiceMethod $service): string
    {
        return sprintf('%s/workerman.task.%s.pid', dirname(Worker::$pidFile), hash('xxh64', $service->toString()));
    }

    private function deleteTaskPid(ServiceMethod $service): void
    {
        @unlink($this->getTaskPidPath($service));
    }
}
