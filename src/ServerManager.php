<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use CrazyGoat\WorkermanBundle\Command\ServerAction;
use CrazyGoat\WorkermanBundle\Exception\ServerAlreadyRunningException;
use CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException;
use CrazyGoat\WorkermanBundle\Exception\ServerStopFailedException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\KernelInterface;
use Workerman\Worker;

final readonly class ServerManager
{
    public function __construct(
        private KernelInterface $kernel,
        private ConfigLoader $configLoader,
        private ProcessInspector $processInspector,
        private StatusFileReader $statusFileReader,
        private LoggerInterface $logger = new NullLogger(),
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

        $this->writeMasterFingerprint($daemon);
        $this->prepareWorkerStart(ServerAction::START, $daemon, $graceful);

        return (new Runner($this->createKernelFactory(), $this->resolveCacheWarmupTimeout(), $this->logger))->run();
    }

    /**
     * @throws ServerNotRunningException
     */
    public function stop(bool $graceful = false): bool
    {
        $masterPid = $this->getRunningMasterPid();
        $parentPid = $this->processInspector->getParentPid($masterPid);
        $fingerprint = $this->loadMasterFingerprint();

        posix_kill($masterPid, $graceful ? \SIGQUIT : \SIGINT);

        if ($graceful) {
            $stopped = $this->processInspector->waitForProcessToStopWithin($masterPid, $this->getGracefulStopTimeout());
        } else {
            $stopped = $this->processInspector->waitForProcessToStop($masterPid, $this->getStopTimeout(), false);
        }

        if (!$stopped) {
            return false;
        }

        $this->processInspector->killOrphanedIntermediateFork($parentPid, $fingerprint, $parentPid);
        $this->cleanupMasterFingerprint();

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

        $this->writeMasterFingerprint($daemon);
        $this->prepareWorkerStart(ServerAction::RESTART, $daemon, $graceful);

        return (new Runner($this->createKernelFactory(), $this->resolveCacheWarmupTimeout(), $this->logger))->run();
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
        $masterPid = $this->getRunningMasterPid();
        $statusFile = $this->statusFileReader->getStatusFilePath();

        @unlink($statusFile);
        posix_kill($masterPid, \SIGIOT);

        if (!$this->statusFileReader->waitForFile($statusFile, $this->statusFileReader->getStatusTimeout())) {
            return null;
        }

        if (!is_readable($statusFile)) {
            return null;
        }

        $content = $this->consumeFile($statusFile);
        if (!\is_string($content) || $content === '') {
            return null;
        }

        $lines = \explode("\n", $content);
        unset($lines[0]);
        $output = \implode("\n", $lines);

        return $output !== '' ? $output : null;
    }

    /**
     * @throws ServerNotRunningException
     */
    public function getConnections(): ?string
    {
        $masterPid = $this->getRunningMasterPid();
        $connectionsFile = $this->statusFileReader->getStatusFilePath() . '.connection';

        @unlink($connectionsFile);
        posix_kill($masterPid, \SIGIO);

        if (!$this->statusFileReader->waitForFile($connectionsFile, $this->statusFileReader->getStatusTimeout())) {
            return null;
        }

        if (!is_readable($connectionsFile)) {
            return null;
        }

        $content = $this->consumeFile($connectionsFile);

        return \is_string($content) && $content !== '' ? $content : null;
    }

    public function isRunning(): bool
    {
        $masterPid = $this->getMasterPid();
        $fingerprint = $this->loadMasterFingerprint();

        return $this->processInspector->isMasterRunning($masterPid, $fingerprint);
    }

    private function prepareWorkerStart(ServerAction $action, bool $daemon, bool $graceful): void
    {
        Worker::$daemonize = $daemon;

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
        $fingerprint = $this->loadMasterFingerprint();

        if (!$this->processInspector->isMasterRunning($masterPid, $fingerprint)) {
            throw $this->notRunningException($masterPid, $fingerprint);
        }

        return $masterPid;
    }

    private function notRunningException(int $masterPid, ?MasterFingerprint $fingerprint): ServerNotRunningException
    {
        if ($masterPid <= 0) {
            return ServerNotRunningException::noPidFile();
        }

        if (!$this->processInspector->isProcessAlive($masterPid)) {
            return ServerNotRunningException::processDead($masterPid);
        }

        return ServerNotRunningException::unverifiable($masterPid, $fingerprint instanceof MasterFingerprint);
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

    private function getGracefulStopTimeout(): int
    {
        $config = $this->configLoader->getWorkermanConfig();
        $timeout = $config['graceful_stop_timeout'] ?? 30;

        return \is_int($timeout) && $timeout >= 1 ? $timeout : 30;
    }

    private function createKernelFactory(): KernelFactory
    {
        return new KernelFactory(
            fn(): KernelInterface => $this->kernel,
            [],
        );
    }

    private function resolveCacheWarmupTimeout(): int
    {
        return CacheWarmupTimeoutConfig::resolve();
    }

    private function consumeFile(string $path): ?string
    {
        $directory = \dirname($path);
        $tempPath = $directory . '/.' . \basename($path) . '.' . \bin2hex(\random_bytes(8)) . '.tmp';

        if (!@\rename($path, $tempPath)) {
            return null;
        }

        $content = @\file_get_contents($tempPath);

        $this->unlinkSafely($tempPath, $path);

        if (!\is_string($content) || $content === '') {
            if (!\is_string($content)) {
                $this->logger->warning('Failed to read renamed status file', [
                    'path' => $path,
                    'temp_path' => $tempPath,
                    'error' => error_get_last()['message'] ?? 'Unknown error',
                ]);
            }
            return null;
        }

        return $content;
    }

    private function unlinkSafely(string $tempPath, string $originalPath): void
    {
        if (!@\unlink($tempPath)) {
            $this->logger->warning('Failed to unlink renamed status file', [
                'path' => $originalPath,
                'temp_path' => $tempPath,
                'error' => error_get_last()['message'] ?? 'Unknown error',
            ]);
        }
    }

    private function writeMasterFingerprint(bool $daemon): void
    {
        $pidFile = $this->getConfiguredPidFile();
        if ($pidFile === '') {
            $this->logger->info('No pid_file configured; skipping master fingerprint');

            return;
        }

        if ($daemon) {
            $this->logger->info('Daemon mode: skipping launcher fingerprint (master fingerprint is written by the master process itself)');

            return;
        }

        try {
            $fingerprint = MasterFingerprint::capture();
            $fingerprint->writeTo($pidFile . '.fingerprint');
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to write master fingerprint; falling back to cmdline check', [
                'pid_file' => $pidFile,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function cleanupMasterFingerprint(): void
    {
        $pidFile = $this->getConfiguredPidFile();
        if ($pidFile === '') {
            return;
        }

        $fingerprintPath = $pidFile . '.fingerprint';
        if (\is_file($fingerprintPath) && !@\unlink($fingerprintPath)) {
            $this->logger->warning('Failed to remove master fingerprint file', [
                'path' => $fingerprintPath,
                'error' => error_get_last()['message'] ?? 'Unknown error',
            ]);
        }
    }

    private function loadMasterFingerprint(): ?MasterFingerprint
    {
        $pidFile = $this->getConfiguredPidFile();
        if ($pidFile === '') {
            return null;
        }

        return MasterFingerprint::readFrom($pidFile . '.fingerprint');
    }

    private function getConfiguredPidFile(): string
    {
        $config = $this->configLoader->getWorkermanConfig();
        $pidFile = $config['pid_file'] ?? '';

        return \is_string($pidFile) ? $pidFile : '';
    }
}
