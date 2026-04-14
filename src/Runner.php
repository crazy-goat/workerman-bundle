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
    private const CACHE_WARMUP_TIMEOUT = 30;

    public function __construct(
        private KernelFactory $kernelFactory,
    ) {
    }

    public function run(): int
    {
        $configLoader = new ConfigLoader(
            projectDir: $this->kernelFactory->getProjectDir(),
            cacheDir: $this->getCacheDir(),
            isDebug: $this->kernelFactory->isDebug(),
        );

        // Warm up cache if no workerman fresh config found (do it in a forked process as the main process should not boot kernel)
        if (!$configLoader->isFresh()) {
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
                // Use posix_kill with different signals to distinguish success/failure:
                // - SIGKILL (9) for success
                // - SIGTERM (15) for error
                // This avoids deadlock with extensions that register shutdown handlers (e.g., grpc)
                \posix_kill((int) \getmypid(), $success ? \SIGKILL : \SIGTERM);
            }

            $timeout = self::CACHE_WARMUP_TIMEOUT;
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
                    throw new \RuntimeException('Cache warmup failed in forked process');
                }
                $signal = \pcntl_wtermsig($status);
                // SIGKILL (9) = success (child killed itself after successful boot)
                // SIGTERM (15) = error (child killed itself after exception)
                if ($signal === \SIGTERM) {
                    throw new \RuntimeException('Cache warmup failed in forked process');
                }
                if ($signal !== \SIGKILL) {
                    throw new \RuntimeException('Cache warmup failed in forked process');
                }
            } elseif (\pcntl_wexitstatus($status) !== 0) {
                throw new \RuntimeException('Cache warmup failed in forked process');
            }
        }

        $config = $configLoader->getWorkermanConfig();
        $schedulerConfig = $configLoader->getSchedulerConfig();
        $processConfig = $configLoader->getProcessConfig();

        $pidFile = $config['pid_file'];
        $logFile = $config['log_file'];
        $stdoutFile = $config['stdout_file'];
        $stopTimeout = $config['stop_timeout'];
        $maxPackageSize = $config['max_package_size'];
        assert(is_string($pidFile));
        assert(is_string($logFile));
        assert(is_string($stdoutFile));
        assert(is_int($stopTimeout));
        assert(is_int($maxPackageSize));

        if (!is_dir($varRunDir = dirname($pidFile))) {
            mkdir(directory: $varRunDir, recursive: true);
        }

        TcpConnection::$defaultMaxPackageSize = $maxPackageSize;
        Worker::$pidFile = $pidFile;
        Worker::$logFile = $logFile;
        Worker::$stdoutFile = $stdoutFile;
        Worker::$stopTimeout = $stopTimeout;
        Worker::$statusFile = (string) preg_replace('/\.pid$/', '.status', $pidFile);
        Worker::$onMasterReload = Utils::clearOpcache(...);

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
            new FileMonitorWorker(
                user: $config['user'],
                group: $config['group'],
                sourceDir: $config['reload_strategy']['file_monitor']['source_dir'],
                filePattern: $config['reload_strategy']['file_monitor']['file_pattern'],
            );
        }

        if ($processConfig !== []) {
            new SupervisorWorker(
                kernelFactory: $this->kernelFactory,
                user: $config['user'],
                group: $config['group'],
                processConfig: $processConfig,
            );
        }

        Worker::runAll();

        return 0;
    }

    private function getCacheDir(): string
    {
        if (isset($_SERVER['APP_CACHE_DIR'])) {
            return $_SERVER['APP_CACHE_DIR'] . '/' . $this->kernelFactory->getEnvironment();
        }

        return $this->kernelFactory->getCacheDir();
    }
}
