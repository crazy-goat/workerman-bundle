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

        $this->writeMasterFingerprint();
        $this->prepareWorkerStart(ServerAction::START, $daemon, $graceful);

        return (new Runner($this->createKernelFactory(), $this->resolveCacheWarmupTimeout()))->run();
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
        $fingerprint = $this->loadMasterFingerprint();

        posix_kill($masterPid, $graceful ? \SIGQUIT : \SIGINT);

        if (!$this->processInspector->waitForProcessToStop($masterPid, $this->getStopTimeout(), $graceful)) {
            return false;
        }

        $this->processInspector->killOrphanedIntermediateFork($parentPid, $fingerprint);

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

        $this->writeMasterFingerprint();
        $this->prepareWorkerStart(ServerAction::RESTART, $daemon, $graceful);

        return (new Runner($this->createKernelFactory(), $this->resolveCacheWarmupTimeout()))->run();
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
        posix_kill($this->getRunningMasterPid(), \SIGIO);

        $connectionsFile = $this->statusFileReader->getStatusFilePath() . '.connection';

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

    private function resolveCacheWarmupTimeout(): int
    {
        return CacheWarmupTimeoutConfig::resolve();
    }

    /**
     * Atomically read and remove a status/connections file.
     *
     * Closes the TOCTOU window between read and unlink: the file at
     * $path is atomically renamed to a unique temporary path within
     * the same directory. After the rename, $path no longer refers to
     * any file (the inode was moved to $tempPath), so a symlink swap
     * at $path cannot redirect the subsequent read or unlink.
     *
     * The unlink always targets the renamed temp file, which is owned
     * by us and is not subject to symlink swaps at the original path.
     *
     * @return string|null The file content, or null if the file was not
     *                     available, was consumed by a concurrent process,
     *                     or could not be read.
     */
    private function consumeFile(string $path): ?string
    {
        $directory = \dirname($path);
        $tempPath = $directory . '/.' . \basename($path) . '.' . \bin2hex(\random_bytes(8)) . '.tmp';

        // The rename may fail if the file was consumed by a concurrent
        // process between waitForFile() and rename(); this is a normal
        // race condition and not an error.
        if (!@\rename($path, $tempPath)) {
            return null;
        }

        $content = @\file_get_contents($tempPath);

        // Best-effort cleanup. The unlink always targets our own temp
        // path, which is not subject to symlink swaps at the original
        // $path. Unlink failures are surfaced to the logger rather
        // than being silently suppressed.
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

    /**
     * Unlink a file, logging a warning when the unlink fails.
     *
     * Used by {@see consumeFile()} to clean up the renamed temp file.
     * The temp file is always a path we created via rename(), so a
     * symlink swap at the original status file path cannot affect
     * this operation.
     */
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

    /**
     * Write the master process fingerprint to a sidecar file.
     *
     * The fingerprint records the PID, start time, and UID of the
     * current process (which will become the Workerman master after
     * `Runner::run()` is invoked). {@see ProcessInspector} reads this
     * fingerprint to verify that a candidate PID really is the master
     * before sending signals — preventing the `/proc/cmdline`
     * substring-match vulnerability described in issue #327.
     *
     * Failures are logged but do not abort the start sequence: if the
     * fingerprint cannot be written, the legacy cmdline-based check
     * is used as a fallback.
     */
    private function writeMasterFingerprint(): void
    {
        $pidFile = $this->getConfiguredPidFile();
        if ($pidFile === '') {
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

    /**
     * Load the master fingerprint from the sidecar file.
     *
     * Returns null if the fingerprint file does not exist, is unreadable,
     * or contains malformed content. Callers should treat a null result
     * as "no fingerprint available" and fall back to the legacy
     * cmdline-based check.
     */
    private function loadMasterFingerprint(): ?MasterFingerprint
    {
        $pidFile = $this->getConfiguredPidFile();
        if ($pidFile === '') {
            return null;
        }

        return MasterFingerprint::readFrom($pidFile . '.fingerprint');
    }

    /**
     * Return the configured PID file path, or an empty string if not set.
     */
    private function getConfiguredPidFile(): string
    {
        $config = $this->configLoader->getWorkermanConfig();
        $pidFile = $config['pid_file'] ?? '';

        return \is_string($pidFile) ? $pidFile : '';
    }
}
