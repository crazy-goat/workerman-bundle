<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Worker;

use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Supervisor\ProcessHandler;
use Workerman\Worker;

readonly class SupervisorWorker
{
    private const PROCESS_TITLE = '[Process]';

    /**
     * @param mixed[] $processConfig
     */
    public function __construct(KernelFactory $kernelFactory, ?string $user, ?string $group, array $processConfig)
    {
        foreach ($processConfig as $serviceId => $serviceConfig) {
            if (!is_array($serviceConfig)) {
                continue;
            }

            if ($serviceConfig['processes'] !== null && $serviceConfig['processes'] <= 0) {
                continue;
            }

            $taskName = empty($serviceConfig['name']) ? $serviceId : $serviceConfig['name'];
            assert(is_string($taskName));
            $processes = $serviceConfig['processes'] ?? 1;
            assert(is_int($processes));
            $worker = new Worker();
            $worker->name = self::PROCESS_TITLE;
            $worker->user = $user ?? '';
            $worker->group = $group ?? '';
            $worker->count = $processes;
            $worker->onWorkerStart = function (Worker $worker) use ($kernelFactory, $serviceId, $serviceConfig, $taskName): never {
                $worker->log(sprintf('%s "%s" started', $worker->name, $taskName));
                $kernel = $kernelFactory->createKernel();
                $kernel->boot();
                /** @var ProcessHandler $handler */
                $handler = $kernel->getContainer()->get('workerman.process_handler');
                $method = empty($serviceConfig['method']) ? '__invoke' : $serviceConfig['method'];
                assert(is_string($method));
                $handler("$serviceId::$method", $taskName);
                $worker->log("Process \"$taskName\" (service: $serviceId::$method) finished unexpectedly");
                exit(1);
            };
        }
    }
}
