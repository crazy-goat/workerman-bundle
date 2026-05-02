<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use CrazyGoat\WorkermanBundle\Command\ServerAction;
use CrazyGoat\WorkermanBundle\Exception\InvalidCacheDirectoryException;
use CrazyGoat\WorkermanBundle\Exception\ServerAlreadyRunningException;
use CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException;
use CrazyGoat\WorkermanBundle\Exception\ServerStopFailedException;
use Symfony\Component\HttpKernel\KernelInterface;
use Workerman\Worker;

final class ServerManager
{
    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function __construct(
        private readonly KernelInterface $kernel,
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
        $parentPid = $this->getParentPid($masterPid);

        posix_kill($masterPid, $graceful ? \SIGQUIT : \SIGINT);

        if (!$this->waitForProcessToStop($masterPid, $this->getStopTimeout(), $graceful)) {
            return false;
        }

        $this->killOrphanedIntermediateFork($parentPid);

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

        $statusFile = $this->getStatusFilePath();

        if (!$this->waitForFile($statusFile, $this->getStatusTimeout())) {
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
        usleep(500_000);

        $connectionsFile = $this->getStatusFilePath() . '.connection';

        if (!is_readable($connectionsFile)) {
            return null;
        }

        $content = file_get_contents($connectionsFile);
        @unlink($connectionsFile);

        return \is_string($content) && $content !== '' ? $content : null;
    }

    public function isRunning(): bool
    {
        return $this->isMasterRunning($this->getMasterPid());
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

        if (!$this->isMasterRunning($masterPid)) {
            throw new ServerNotRunningException();
        }

        return $masterPid;
    }

    private function waitForProcessToStop(int $pid, int $stopTimeout, bool $graceful): bool
    {
        // Graceful stop gets longer timeout (3x + 3s buffer), ensuring it's always longer than regular
        $timeout = $graceful ? $stopTimeout * 3 + 3 : $stopTimeout + 3;
        $startTime = time();
        $sleepMs = 10;

        while (true) {
            if (!$this->isProcessAlive($pid)) {
                return true;
            }

            // Always check timeout, regardless of graceful mode
            if ((time() - $startTime) >= $timeout) {
                return false;
            }

            // Exponential backoff: start at 10ms, max 250ms
            usleep($sleepMs * 1000);
            $sleepMs = min($sleepMs * 2, 250);
        }
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0 || !posix_kill($pid, 0)) {
            return false;
        }

        $statusFile = "/proc/{$pid}/status";
        if (is_readable($statusFile)) {
            $status = file_get_contents($statusFile);
            if (\is_string($status) && preg_match('/^State:\s+Z/m', $status)) {
                return false;
            }
        }

        return true;
    }

    private function getParentPid(int $pid): int
    {
        $statusFile = "/proc/{$pid}/status";
        if (!is_readable($statusFile)) {
            return 0;
        }

        $status = file_get_contents($statusFile);
        if (\is_string($status) && preg_match('/^PPid:\s+(\d+)/m', $status, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function killOrphanedIntermediateFork(int $parentPid): void
    {
        if ($parentPid <= 0 || !$this->isProcessAlive($parentPid)) {
            return;
        }

        $cmdline = "/proc/{$parentPid}/cmdline";
        if (!is_readable($cmdline)) {
            return;
        }

        $content = file_get_contents($cmdline);
        if (\is_string($content) && str_contains($content, 'WorkerMan')) {
            posix_kill($parentPid, \SIGKILL);
        }
    }

    private function isMasterRunning(int $masterPid): bool
    {
        if ($masterPid <= 0 || !$this->isProcessAlive($masterPid)) {
            return false;
        }

        $cmdline = "/proc/{$masterPid}/cmdline";
        if (is_readable($cmdline)) {
            $content = file_get_contents($cmdline);
            if (\is_string($content) && $content !== '') {
                return str_contains($content, 'WorkerMan') || str_contains($content, 'php');
            }
        }

        return true;
    }

    private function getMasterPid(): int
    {
        $pidFile = $this->getConfig()['pid_file'] ?? '';

        if (!\is_string($pidFile) || $pidFile === '' || !is_file($pidFile)) {
            return 0;
        }

        $content = file_get_contents($pidFile);

        return \is_string($content) ? (int) $content : 0;
    }

    private function getStopTimeout(): int
    {
        $timeout = $this->getConfig()['stop_timeout'] ?? 2;

        return \is_int($timeout) ? $timeout : 2;
    }

    private function getStatusTimeout(): int
    {
        $timeout = $this->getConfig()['status_timeout'] ?? 5;

        return \is_int($timeout) ? $timeout : 5;
    }

    /**
     * Poll for a file to exist, with a configurable timeout.
     *
     * Checks every 50ms whether the file exists, up to $timeout seconds.
     *
     * @return bool true if file exists within timeout, false otherwise
     */
    private function waitForFile(string $filePath, int $timeout): bool
    {
        $interval = 50_000; // 50ms in microseconds
        $elapsed = 0;
        $timeoutMicro = $timeout * 1_000_000;

        while (!file_exists($filePath) && $elapsed < $timeoutMicro) {
            usleep($interval);
            $elapsed += $interval;
        }

        return file_exists($filePath);
    }

    private function getStatusFilePath(): string
    {
        $pidFile = $this->getConfig()['pid_file'] ?? '';

        if (!\is_string($pidFile)) {
            return '';
        }

        return preg_replace('/\.pid$/', '.status', $pidFile) ?? $pidFile;
    }

    /**
     * @return array<string, mixed>
     */
    private function getConfig(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $cacheDir = $this->kernel->getContainer()->getParameter('kernel.cache_dir');

        if (!\is_string($cacheDir)) {
            throw new InvalidCacheDirectoryException('kernel.cache_dir parameter must be a string');
        }

        $configLoader = new ConfigLoader(
            projectDir: $this->kernel->getProjectDir(),
            cacheDir: $cacheDir,
            isDebug: $this->kernel->isDebug(),
        );

        return $this->config = $configLoader->getWorkermanConfig();
    }

    private function createKernelFactory(): KernelFactory
    {
        return new KernelFactory(
            fn(): KernelInterface => $this->kernel,
            [],
        );
    }
}
