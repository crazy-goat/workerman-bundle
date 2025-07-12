<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Worker;

use CrazyGoat\WorkermanBundle\Http\MiddlewareDispatchInterface;
use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Http\StaticFileHandlerInterface;
use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use CrazyGoat\WorkermanBundle\Utils;
use Workerman\Protocols\Http;
use Workerman\Worker;

final class ServerWorker
{
    protected const PROCESS_TITLE = '[Server]';

    /**
     * @param mixed[] $serverConfig
     */
    public function __construct(
        KernelFactory $kernelFactory,
        ?string       $user,
        ?string       $group,
        array         $serverConfig,
    ) {
        $listen = $serverConfig['listen'] ?? '';
        assert(is_string($listen));
        $transport = 'tcp';
        $context = [];

        if (str_starts_with($listen, 'https://')) {
            $listen = str_replace('https://', 'http://', $listen);
            $transport = 'ssl';
            $context = [
                'ssl' => [
                    'local_cert' => $serverConfig['local_cert'] ?? '',
                    'local_pk' => $serverConfig['local_pk'] ?? '',
                ],
            ];
        } elseif (str_starts_with($listen, 'ws://')) {
            $listen = str_replace('ws://', 'websocket://', $listen);
        } elseif (str_starts_with($listen, 'wss://')) {
            $listen = str_replace('wss://', 'websocket://', $listen);
            $transport = 'ssl';
            $context = [
                'ssl' => [
                    'local_cert' => $serverConfig['local_cert'] ?? '',
                    'local_pk' => $serverConfig['local_pk'] ?? '',
                ],
            ];
        }

        $worker = new Worker($listen, $context);
        $worker->name = sprintf('%s "%s"', self::PROCESS_TITLE, $serverConfig['name']);
        $worker->user = $user ?? '';
        $worker->group = $group ?? '';
        $worker->count = $serverConfig['processes'] ?? Utils::cpuCount() * 2;
        $worker->transport = $transport;
        $worker->reusePort = boolval($serverConfig['reuse_port'] ?? false);

        $worker->onWorkerStart = function (Worker $worker) use ($kernelFactory, $serverConfig): void {
            Http::requestClass(Request::class);

            $serveFiles = $serverConfig['serve_files'] ?? false;
            $rootDir = $serveFiles ? $serverConfig['root_dir'] ?? null : null;

            $worker->log(sprintf('%s "%s" started', $worker->name, $serverConfig['name']));
            $kernel = $kernelFactory->createKernel();
            $kernel->boot();
            $callable = $kernel->getContainer()->get('workerman.http_request_handler');
            $middlewares = array_map(function (string $middleware) use ($kernel): MiddlewareInterface {
                $service = $kernel->getContainer()->get($middleware);
                if (!$service instanceof MiddlewareInterface) {
                    throw new \InvalidArgumentException(sprintf('Service "%s" must implement "%s"', $middleware, MiddlewareInterface::class));
                }
                return $service;
            }, $serverConfig['middlewares'] ?? []);
            assert(is_callable($callable));
            if ($callable instanceof StaticFileHandlerInterface) {
                $callable->withRootDirectory($rootDir);
            }
            if ($callable instanceof MiddlewareDispatchInterface && $middlewares !== []) {
                $callable->withMiddlewares(...$middlewares);
            }
            $worker->onMessage = $callable;
        };
    }
}
