<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Worker;

use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Scheduler\TaskHandler;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\TriggerFactory;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\TriggerInterface;
use CrazyGoat\WorkermanBundle\Utils;
use Workerman\Timer;
use Workerman\Worker;

final class SchedulerWorker
{
    private const PROCESS_TITLE = '[Scheduler]';

    private readonly Worker $worker;
    private TaskHandler $handler;

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
            $this->worker->log($this->worker->name . 'started');
            pcntl_signal(SIGCHLD, SIG_IGN);
            $kernel = $kernelFactory->createKernel();
            $kernel->boot();
            $handler = $kernel->getContainer()->get('workerman.task_handler');
            assert($handler instanceof TaskHandler);
            $this->handler = $handler;

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
                $service = "$serviceId::$method";
                $this->deleteTaskPid($service);
                $this->scheduleCallback($trigger, $service, $taskName);
            }
        };
    }

    private function scheduleCallback(TriggerInterface $trigger, string $service, string $taskName): void
    {
        $currentDate = new \DateTimeImmutable();
        $nextRunDate = $trigger->getNextRunDate($currentDate);
        if ($nextRunDate instanceof \DateTimeImmutable) {
            $interval = $nextRunDate->getTimestamp() - $currentDate->getTimestamp();
            Timer::add($interval, $this->runCallback(...), [$trigger, $service, $taskName], false);
        }
    }

    private function runCallback(TriggerInterface $trigger, string $service, string $taskName): void
    {
        $taskPid = Utils::getPid($this->getTaskPidPath($service));
        if ($taskPid !== 0) {
            $this->scheduleCallback($trigger, $service, $taskName);
            return;
        }

        $pid = \pcntl_fork();
        if ($pid === -1) {
            $this->worker->log(sprintf('%s Task "%s" call error!', $this->worker->name, $taskName));
        } elseif ($pid > 0) {
            $this->worker->log(sprintf('%s Task "%s" called', $this->worker->name, $taskName));
            $this->scheduleCallback($trigger, $service, $taskName);
        } else {
            // Child process start
            Timer::delAll();
            $title = str_replace(self::PROCESS_TITLE, sprintf('%s "%s"', self::PROCESS_TITLE, $taskName), strval(cli_get_process_title()));
            cli_set_process_title($title);
            $this->saveTaskPid($service);
            ($this->handler)($service, $taskName);
            $this->deleteTaskPid($service);
            posix_kill(posix_getpid(), SIGKILL);
        }
    }

    private function getTaskPidPath(string $serviceId): string
    {
        return sprintf('%s/workerman.task.%s.pid', dirname(Worker::$pidFile), hash('xxh64', $serviceId));
    }

    private function saveTaskPid(string $service): void
    {
        $pidFile = $this->getTaskPidPath($service);
        if (file_put_contents($pidFile, posix_getpid()) === false) {
            $this->worker->log(sprintf('%s Can\'t save pid to %s', $this->worker->name, $pidFile));
        }
    }

    private function deleteTaskPid(string $service): void
    {
        $pidFile = $this->getTaskPidPath($service);
        if (is_file($pidFile)) {
            unlink($pidFile);
        }
    }
}
