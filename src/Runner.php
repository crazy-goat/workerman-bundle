<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use CrazyGoat\WorkermanBundle\Worker\FileMonitorWorker;
use CrazyGoat\WorkermanBundle\Worker\SchedulerWorker;
use CrazyGoat\WorkermanBundle\Worker\ServerWorker;
use CrazyGoat\WorkermanBundle\Worker\SupervisorWorker;
use Symfony\Component\Runtime\RunnerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

final readonly class Runner implements RunnerInterface
{
    public function __construct(
        private KernelFactory $kernelFactory,
    ) {
    }

    public function run(): int
    {
        $configLoader = $this->createConfigLoader();

        $this->warmUpCache($configLoader);

        $config = $configLoader->getWorkermanConfig();
        $schedulerConfig = $configLoader->getSchedulerConfig();
        $processConfig = $configLoader->getProcessConfig();

        $this->applyWorkermanConfig($config);
        $this->createWorkers($config, $schedulerConfig, $processConfig);

        Worker::runAll();

        return 0;
    }

    private function createConfigLoader(): ConfigLoader
    {
        return new ConfigLoader(
            projectDir: $this->kernelFactory->getProjectDir(),
            cacheDir: $this->getCacheDir(),
            isDebug: $this->kernelFactory->isDebug(),
        );
    }

    /**
     * Warm up cache in a forked process so the main process never boots the kernel.
     *
     * Uses posix_kill with different signals to distinguish success/failure:
     * - SIGKILL (9) for success
     * - SIGTERM (15) for error
     * This avoids deadlock with extensions that register shutdown handlers (e.g., grpc).
     *
     * @throws \RuntimeException on fork failure, timeout, or unexpected child status
     */
    private function warmUpCache(ConfigLoader $configLoader): void
    {
        if ($configLoader->isFresh()) {
            return;
        }

        $pid = \pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Failed to fork process for cache warmup');
        }

        if ($pid === 0) {
            $success = false;
            try {
                $this->kernelFactory->createKernel()->boot();
                $success = true;
            } catch (\Throwable $e) {
                fwrite(STDERR, $e->getMessage() . PHP_EOL);
            }

            \posix_kill((int) \getmypid(), $success ? \SIGKILL : \SIGTERM);
        }

        $timeout = $this->getCacheWarmupTimeout();
        $deadline = \time() + $timeout;
        $status = 0;

        while (true) {
            $result = \pcntl_waitpid($pid, $status, WNOHANG);

            if ($result === $pid) {
                break;
            }

            if ($result === -1) {
                throw new \RuntimeException('Failed to wait for cache warmup process');
            }

            if (\time() >= $deadline) {
                \posix_kill($pid, \SIGKILL);
                \pcntl_waitpid($pid, $status, 0);
                throw new \RuntimeException(\sprintf('Cache warmup timed out after %d seconds', $timeout));
            }

            \usleep(100_000);
        }

        if (!\pcntl_wifexited($status)) {
            if (!\pcntl_wifsignaled($status)) {
                throw new \RuntimeException(\sprintf(
                    'Cache warmup failed in forked process (unexpected status: %d)',
                    $status,
                ));
            }

            $signal = \pcntl_wtermsig($status);
            if ($signal === \SIGTERM) {
                throw new \RuntimeException('Cache warmup failed in forked process (child signaled failure via SIGTERM)');
            }

            if ($signal !== \SIGKILL) {
                throw new \RuntimeException(\sprintf(
                    'Cache warmup failed in forked process (killed by unexpected signal %d)',
                    $signal,
                ));
            }
        } elseif (\pcntl_wexitstatus($status) !== 0) {
            throw new \RuntimeException(\sprintf(
                'Cache warmup failed in forked process (exit code %d)',
                \pcntl_wexitstatus($status),
            ));
        }
    }

    /**
     * Apply resolved config paths to Worker and TcpConnection static properties.
     *
     * Also ensures runtime directories exist. This is critical in PHAR mode
     * where directories live outside the archive and may not exist yet.
     *
     * @param mixed[] $config
     *
     * @throws \RuntimeException when a runtime directory cannot be created
     */
    private function applyWorkermanConfig(array $config): void
    {
        $pidFile = $this->resolveRuntimePath($config['pid_file']);
        $logFile = $this->resolveRuntimePath($config['log_file']);
        $stdoutFile = $this->resolveRuntimePath($config['stdout_file']);
        $stopTimeout = $config['stop_timeout'];
        $maxPackageSize = $config['max_package_size'];
        assert(is_int($stopTimeout));
        assert(is_int($maxPackageSize));

        foreach ([
            dirname($pidFile),
            dirname($logFile),
            dirname($stdoutFile),
        ] as $runtimeDir) {
            if (!is_dir($runtimeDir) && !mkdir(directory: $runtimeDir, recursive: true) && !is_dir($runtimeDir)) {
                throw new \RuntimeException(\sprintf('Unable to create directory "%s".', $runtimeDir));
            }
        }

        TcpConnection::$defaultMaxPackageSize = $maxPackageSize;
        Worker::$pidFile = $pidFile;
        Worker::$logFile = $logFile;
        Worker::$stdoutFile = $stdoutFile;
        Worker::$stopTimeout = $stopTimeout;
        Worker::$statusFile = (string) preg_replace('/\.pid$/', '.status', $pidFile);
        Worker::$onMasterReload = Utils::clearOpcache(...);
    }

    /**
     * Create and register all worker types based on config.
     *
     * Workers register themselves via constructor side-effects (no return value needed).
     * File monitor is skipped in PHAR mode — files are frozen inside the archive.
     *
     * @param mixed[] $config
     * @param mixed[] $schedulerConfig
     * @param mixed[] $processConfig
     */
    private function createWorkers(array $config, array $schedulerConfig, array $processConfig): void
    {
        assert(is_array($config['servers']));
        foreach ($config['servers'] as $serverConfig) {
            new ServerWorker(
                kernelFactory: $this->kernelFactory,
                user: $config['user'],
                group: $config['group'],
                serverConfig: $serverConfig,
            );
        }

        if ($schedulerConfig !== []) {
            new SchedulerWorker(
                kernelFactory: $this->kernelFactory,
                user: $config['user'],
                group: $config['group'],
                schedulerConfig: $schedulerConfig,
            );
        }

        if ($config['reload_strategy']['file_monitor']['active'] && $this->kernelFactory->isDebug()) {
            if ($this->kernelFactory->isPhar()) {
                Worker::log('File monitor is disabled in PHAR mode. Use restart to apply code changes.');
            } else {
                new FileMonitorWorker(
                    user: $config['user'],
                    group: $config['group'],
                    sourceDir: $config['reload_strategy']['file_monitor']['source_dir'],
                    filePattern: $config['reload_strategy']['file_monitor']['file_pattern'],
                );
            }
        }

        if ($processConfig !== []) {
            new SupervisorWorker(
                kernelFactory: $this->kernelFactory,
                user: $config['user'],
                group: $config['group'],
                processConfig: $processConfig,
            );
        }
    }

    private function getCacheWarmupTimeout(): int
    {
        if (isset($_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT']) && $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] !== '') {
            $timeout = (int) $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'];
            if ($timeout < 1) {
                throw new \InvalidArgumentException('WORKERMAN_CACHE_WARMUP_TIMEOUT must be a positive integer');
            }

            return $timeout;
        }

        return 30;
    }

    private function getCacheDir(): string
    {
        if (isset($_SERVER['APP_CACHE_DIR']) && $_SERVER['APP_CACHE_DIR'] !== '') {
            return $_SERVER['APP_CACHE_DIR'] . '/' . $this->kernelFactory->getEnvironment();
        }

        return $this->kernelFactory->getCacheDir();
    }

    /**
     * Resolves a path that was configured relative to project_dir,
     * replacing the project_dir prefix with runtime_dir when in PHAR mode.
     */
    private function resolveRuntimePath(string $path): string
    {
        $projectDir = $this->kernelFactory->getProjectDir();
        $runtimeDir = $this->kernelFactory->getRuntimeDir();

        // If running from PHAR, replace the project dir prefix with runtime dir
        if ($runtimeDir !== $projectDir && str_starts_with($path, $projectDir)) {
            return $runtimeDir . substr($path, strlen($projectDir));
        }

        return $path;
    }
}
