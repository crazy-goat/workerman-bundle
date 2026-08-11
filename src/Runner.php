<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use CrazyGoat\WorkermanBundle\Worker\FileMonitorWorker;
use CrazyGoat\WorkermanBundle\Worker\MasterWorker;
use CrazyGoat\WorkermanBundle\Worker\SchedulerWorker;
use CrazyGoat\WorkermanBundle\Worker\ServerWorker;
use CrazyGoat\WorkermanBundle\Worker\SupervisorWorker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

readonly class Runner implements RunnerInterface
{
    public function __construct(
        private KernelFactory $kernelFactory,
        private int $cacheWarmupTimeout = CacheWarmupTimeoutConfig::DEFAULT,
        private ?LoggerInterface $logger = null,
    ) {
        if ($this->cacheWarmupTimeout < 1) {
            throw new \InvalidArgumentException(\sprintf(
                '%s must be a positive integer, got %d',
                CacheWarmupTimeoutConfig::ENV_VAR,
                $this->cacheWarmupTimeout,
            ));
        }
    }

    public function run(): int
    {
        $configLoader = $this->createConfigLoader();

        $this->warmUpCache($configLoader);

        $config = $configLoader->getWorkermanConfig();
        $schedulerConfig = $configLoader->getSchedulerConfig();
        $processConfig = $configLoader->getProcessConfig();

        $this->applyWorkermanConfig($config);
        $this->warnAboutGrpcExtension();
        $this->createWorkers($config, $schedulerConfig, $processConfig);

        // Run through MasterWorker so the real master PID (which is only
        // known after daemonize) gets a fingerprint — closing the daemon-mode
        // verification gap described in issue #584.
        MasterWorker::runAll();

        return 0;
    }

    private function createConfigLoader(): ConfigLoader
    {
        return new ConfigLoader(
            projectDir: $this->kernelFactory->getProjectDir(),
            cacheDir: $this->getCacheDir(),
            isDebug: $this->kernelFactory->isDebug(),
            logger: $this->logger,
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

        $pid = $this->fork();
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

            \posix_kill(\posix_getpid(), $success ? \SIGKILL : \SIGTERM);
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

        $pidDir = dirname($pidFile);
        if (!is_dir($pidDir) && (!mkdir(directory: $pidDir, permissions: 0700, recursive: true) && !is_dir($pidDir))) {
            throw new \RuntimeException(\sprintf('Unable to create directory "%s".', $pidDir));
        }

        foreach ([
            dirname($logFile),
            dirname($stdoutFile),
        ] as $runtimeDir) {
            if (!is_dir($runtimeDir) && !mkdir(directory: $runtimeDir, permissions: 0700, recursive: true) && !is_dir($runtimeDir)) {
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
     * Emit one-time start-up warnings for grpc hosts (no-op otherwise).
     *
     * The grpc extension interacts badly with forked children in both
     * directions: without GRPC_ENABLE_FORK_SUPPORT children deadlock early
     * (see README), and with it set, grpc_shutdown() can hang when a child
     * exits (mitigated via SIGKILL in ProcessTerminator). Must run after
     * applyWorkermanConfig() so Worker::$logFile is already configured.
     */
    private function warnAboutGrpcExtension(): void
    {
        if (!\extension_loaded('grpc')) {
            return;
        }

        $forkSupport = $_ENV['GRPC_ENABLE_FORK_SUPPORT'] ?? \getenv('GRPC_ENABLE_FORK_SUPPORT');
        if ($forkSupport !== '1' && $forkSupport !== 'true') {
            $this->logStartupWarning('[WARN] grpc extension detected but GRPC_ENABLE_FORK_SUPPORT is not set: forked children (scheduler tasks, supervised processes) can deadlock. Set GRPC_ENABLE_FORK_SUPPORT=1 before starting the server — see docs/troubleshooting.md "gRPC Extension and Fork Safety".');

            return;
        }

        $this->logStartupWarning('[WARN] grpc extension detected: supervised processes and forked task children are terminated with SIGKILL on completion because grpc_shutdown() can hang in forked children — destructors and shutdown functions are skipped for them. See docs/troubleshooting.md "gRPC Extension and Fork Safety".');
    }

    /**
     * Write a start-up warning to the configured Workerman log file.
     *
     * Only the log file is used: writing to stderr before daemonize() is
     * unsafe on grpc hosts where `start -d` is spawned with a closed stderr
     * pipe (e.g. via proc_open in tests) - the write hits SIGPIPE and the
     * launcher dies before forking the daemon. Worker::log() itself cannot
     * be used either: its safeEcho() path reads Worker::$outputStream,
     * which is only initialized inside runAll() and would throw feof() on
     * null. This runs before daemonize, so the log entry is written once
     * by the launcher process.
     */
    private function logStartupWarning(string $msg): void
    {
        if (Worker::$logFile !== '') {
            \file_put_contents(
                Worker::$logFile,
                \sprintf("[%s] %s\n", \date('Y-m-d H:i:s'), $msg),
                \FILE_APPEND | \LOCK_EX,
            );
        }
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
                connectionTimeout: $config['connection_timeout'] ?? 120,
                keepaliveTimeout: $config['keepalive_timeout'] ?? 30,
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

    protected function fork(): int
    {
        return \pcntl_fork();
    }

    private function getCacheWarmupTimeout(): int
    {
        return $this->cacheWarmupTimeout;
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
        return PharHelper::resolveRuntimePath($path, $this->kernelFactory->getProjectDir());
    }
}
