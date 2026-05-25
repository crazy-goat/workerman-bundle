<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use CrazyGoat\WorkermanBundle\Command\ServerAction;
use CrazyGoat\WorkermanBundle\Exception\ServerAlreadyRunningException;
use CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException;
use CrazyGoat\WorkermanBundle\Exception\ServerStopFailedException;
use Symfony\Component\HttpKernel\KernelInterface;
use Workerman\Worker;

final readonly class ServerManager
{
    public function __construct(
        private KernelInterface $kernel,
        private ConfigLoader $configLoader,
        private ProcessInspector $processInspector,
        private StatusFileReader $statusFileReader,
    ) {
    }

    /**
     * @throws ServerAlreadyRunningException
     */
    public function start(bool $daemon = false, bool $graceful = false): int
    {
        if ($this->isRunning()) {
            throw new ServerAlreadyRunningException();
        }

        $this->prepareWorkerStart(ServerAction::START, $daemon, $graceful);

        return (new Runner($this->createKernelFactory()))->run();
    }

    /**
     * @return bool true if stopped within timeout
     *
     * @throws ServerNotRunningException
     */
    public function stop(bool $graceful = false): bool
    {
        $masterPid = $this->getRunningMasterPid();
        $parentPid = $this->processInspector->getParentPid($masterPid);

        posix_kill($masterPid, $graceful ? \SIGQUIT : \SIGINT);

        if (!$this->processInspector->waitForProcessToStop($masterPid, $this->getStopTimeout(), $graceful)) {
            return false;
        }

        $this->processInspector->killOrphanedIntermediateFork($parentPid);

        return true;
    }

    /**
     * @throws ServerStopFailedException
     */
    public function restart(bool $daemon = false, bool $graceful = false): int
    {
        if ($this->isRunning()) {
            try {
                if (!$this->stop($graceful)) {
                    throw new ServerStopFailedException();
                }
            } catch (ServerNotRunningException) {
            }
        }

        $this->prepareWorkerStart(ServerAction::RESTART, $daemon, $graceful);

        return (new Runner($this->createKernelFactory()))->run();
    }

    /**
     * @throws ServerNotRunningException
     */
    public function reload(bool $graceful = false): void
    {
        posix_kill($this->getRunningMasterPid(), $graceful ? \SIGUSR2 : \SIGUSR1);
    }

    /**
     * @throws ServerNotRunningException
     */
    public function getStatus(): ?string
    {
        posix_kill($this->getRunningMasterPid(), \SIGIOT);

        $statusFile = $this->statusFileReader->getStatusFilePath();

        if (!$this->statusFileReader->waitForFile($statusFile, $this->statusFileReader->getStatusTimeout())) {
            return null;
        }

        if (!is_readable($statusFile)) {
            return null;
        }

        $lines = file($statusFile, \FILE_IGNORE_NEW_LINES);
        @unlink($statusFile);

        if (!\is_array($lines) || $lines === []) {
            return null;
        }

        unset($lines[0]);
        $output = implode("\n", $lines);

        return $output !== '' ? $output : null;
    }

    /**
     * @throws ServerNotRunningException
     */
    public function getConnections(): ?string
    {
        posix_kill($this->getRunningMasterPid(), \SIGIO);

        $connectionsFile = $this->statusFileReader->getStatusFilePath() . '.connection';

        if (!$this->statusFileReader->waitForFile($connectionsFile, $this->statusFileReader->getStatusTimeout())) {
            return null;
        }

        if (!is_readable($connectionsFile)) {
            return null;
        }

        $content = file_get_contents($connectionsFile);
        @unlink($connectionsFile);

        return \is_string($content) && $content !== '' ? $content : null;
    }

    public function isRunning(): bool
    {
        return $this->processInspector->isMasterRunning($this->getMasterPid());
    }

    private function prepareWorkerStart(ServerAction $action, bool $daemon, bool $graceful): void
    {
        $command = $action->value;
        if ($daemon) {
            $command .= ' -d';
        }
        if ($graceful) {
            $command .= ' -g';
        }

        Worker::$command = $command;

        $signals = [\SIGINT, \SIGTERM, \SIGHUP, \SIGTSTP, \SIGQUIT, \SIGUSR1, \SIGUSR2, \SIGIOT, \SIGIO, \SIGPIPE];
        foreach ($signals as $signal) {
            pcntl_signal($signal, \SIG_DFL);
        }
    }

    /**
     * @throws ServerNotRunningException
     */
    private function getRunningMasterPid(): int
    {
        $masterPid = $this->getMasterPid();

        if (!$this->processInspector->isMasterRunning($masterPid)) {
            throw new ServerNotRunningException();
        }

        return $masterPid;
    }

    private function getMasterPid(): int
    {
        $config = $this->configLoader->getWorkermanConfig();
        $pidFile = $config['pid_file'] ?? '';

        if (!\is_string($pidFile) || $pidFile === '' || !is_file($pidFile)) {
            return 0;
        }

        $content = file_get_contents($pidFile);

        return \is_string($content) ? (int) $content : 0;
    }

    private function getStopTimeout(): int
    {
        $config = $this->configLoader->getWorkermanConfig();
        $timeout = $config['stop_timeout'] ?? 2;

        return \is_int($timeout) ? $timeout : 2;
    }

    private function createKernelFactory(): KernelFactory
    {
        return new KernelFactory(
            fn(): KernelInterface => $this->kernel,
            [],
        );
    }
}
