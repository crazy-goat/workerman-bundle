<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Worker;

use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Supervisor\ProcessHandler;
use CrazyGoat\WorkermanBundle\Util\ProcessTerminator;
use CrazyGoat\WorkermanBundle\Util\ServiceMethod;
use Workerman\Worker;

final readonly class SupervisorWorker
{
    private const PROCESS_TITLE = '[Process]';

    /**
     * @param mixed[] $processConfig
     * @param mixed[] $restartConfig
     */
    public function __construct(
        KernelFactory $kernelFactory,
        ?string $user,
        ?string $group,
        array $processConfig,
        array $restartConfig = [],
        string $runtimeDir = '',
    ) {
        foreach ($processConfig as $serviceId => $serviceConfig) {
            if (!is_array($serviceConfig)) {
                continue;
            }

            if (isset($serviceConfig['processes']) && $serviceConfig['processes'] <= 0) {
                continue;
            }

            $taskName = empty($serviceConfig['name']) ? $serviceId : $serviceConfig['name'];
            assert(is_string($taskName));
            $processes = $serviceConfig['processes'] ?? 1;
            assert(is_int($processes));
            $method = empty($serviceConfig['method']) ? '__invoke' : $serviceConfig['method'];
            assert(is_string($method));
            $restartDelay = isset($serviceConfig['restart_delay']) ? (int) $serviceConfig['restart_delay'] : null;
            $maxRestartDelay = isset($serviceConfig['max_restart_delay']) ? (int) $serviceConfig['max_restart_delay'] : null;
            if ($restartDelay !== null && $restartDelay < 0) {
                throw new \InvalidArgumentException(sprintf('Process "%s" has a negative restart_delay.', $taskName));
            }
            if ($maxRestartDelay !== null && $maxRestartDelay < 0) {
                throw new \InvalidArgumentException(sprintf('Process "%s" has a negative max_restart_delay.', $taskName));
            }
            $backoff = RestartBackoff::fromConfig($runtimeDir, $restartConfig, $restartDelay, $maxRestartDelay);
            $stateKey = sprintf('process:%s::%s', $serviceId, $method);
            $worker = new Worker();
            $worker->name = self::PROCESS_TITLE;
            $worker->user = $user ?? '';
            $worker->group = $group ?? '';
            $worker->count = $processes;
            $worker->onWorkerReload = static function (Worker $worker) use ($backoff, $stateKey): void {
                $backoff->forget(sprintf('%s:%d', $stateKey, $worker->id));
            };
            $worker->onWorkerStart = function (Worker $worker) use ($kernelFactory, $serviceId, $taskName, $method, $stateKey, $backoff): never {
                $backoff->beforeStart(
                    sprintf('%s:%d', $stateKey, $worker->id),
                    'Process',
                    $taskName,
                    static fn(string $message) => $worker->log($message),
                );
                $worker->log(sprintf('%s "%s" started', $worker->name, $taskName));
                $kernel = $kernelFactory->createKernel();
                $kernel->boot();
                /** @var ProcessHandler $handler */
                $handler = $kernel->getContainer()->get('workerman.process_handler');
                $handler(new ServiceMethod($serviceId, $method), $taskName);
                $worker->log("Process \"$taskName\" (service: $serviceId::$method) finished unexpectedly");
                ProcessTerminator::terminate(1);
            };
        }
    }
}
