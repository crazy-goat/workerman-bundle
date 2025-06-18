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

final class Runner implements RunnerInterface
{
    public function __construct(
        private readonly KernelFactory $kernelFactory,
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
            if (\pcntl_fork() === 0) {
                $this->kernelFactory->createKernel()->boot();
                exit;
            } else {
                pcntl_wait($status);
                unset($status);
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
            return $_SERVER['APP_CACHE_DIR'].'/'.$this->kernelFactory->getEnvironment();
        }

        return $this->kernelFactory->getCacheDir();
    }
}
