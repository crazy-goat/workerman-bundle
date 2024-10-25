<?php

declare(strict_types=1);

namespace Luzrain\WorkermanBundle\Worker;

use Luzrain\WorkermanBundle\KernelFactory;
use Luzrain\WorkermanBundle\Supervisor\ProcessHandler;
use Workerman\Worker;

final class SupervisorWorker
{
    private const PROCESS_TITLE = '[Process]';

    /**
     * @param mixed[] $processConfig
     */
    public function __construct(KernelFactory $kernelFactory, ?string $user, ?string $group, array $processConfig)
    {
        foreach ($processConfig as $serviceId => $serviceConfig) {
            if ($serviceConfig['processes'] !== null && $serviceConfig['processes'] <= 0) {
                continue;
            }

            $taskName = empty($serviceConfig['name']) ? $serviceId : $serviceConfig['name'];
            $worker = new Worker();
            $worker->name = self::PROCESS_TITLE;
            $worker->user = $user ?? '';
            $worker->group = $group ?? '';
            $worker->count = $serviceConfig['processes'] ?? 1;
            $worker->onWorkerStart = function (Worker $worker) use ($kernelFactory, $serviceId, $serviceConfig, $taskName): void {
                $worker->log(sprintf('%s "%s" started', $worker->name, $taskName));
                $kernel = $kernelFactory->createKernel();
                $kernel->boot();
                /** @var ProcessHandler $handler */
                $handler = $kernel->getContainer()->get('workerman.process_handler');
                $method = empty($serviceConfig['method']) ? '__invoke' : $serviceConfig['method'];
                $handler("$serviceId::$method", $taskName);
                sleep(1);
                exit;
            };
        }
    }
}
