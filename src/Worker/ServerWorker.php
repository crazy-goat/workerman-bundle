<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Worker;

use CrazyGoat\WorkermanBundle\Exception\InvalidMiddlewareException;
use CrazyGoat\WorkermanBundle\Http\MiddlewareDispatchInterface;
use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Http\StaticFileHandlerInterface;
use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use CrazyGoat\WorkermanBundle\Utils;
use Workerman\Protocols\Http;
use Workerman\Worker;

final readonly class ServerWorker
{
    private const PROCESS_TITLE = '[Server]';

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
            $context = $this->createSslContext($serverConfig);
        } elseif (str_starts_with($listen, 'ws://')) {
            $listen = str_replace('ws://', 'websocket://', $listen);
        } elseif (str_starts_with($listen, 'wss://')) {
            $listen = str_replace('wss://', 'websocket://', $listen);
            $transport = 'ssl';
            $context = $this->createSslContext($serverConfig);
        }

        $worker = new Worker($listen, $context);
        $worker->name = sprintf('%s %s', self::PROCESS_TITLE, $serverConfig['name']);
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
                    throw new InvalidMiddlewareException(sprintf('Service "%s" must implement "%s"', $middleware, MiddlewareInterface::class));
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

    /**
     * @param mixed[] $serverConfig
     * @return array{ssl: array{local_cert: string, local_pk: string}}
     */
    private function createSslContext(array $serverConfig): array
    {
        $cert = $serverConfig['local_cert'] ?? null;
        $key = $serverConfig['local_pk'] ?? null;

        if (!is_string($cert) || $cert === '') {
            throw new \InvalidArgumentException(
                'SSL configuration requires "local_cert" option for HTTPS/WSS server.',
            );
        }

        if (!is_string($key) || $key === '') {
            throw new \InvalidArgumentException(
                'SSL configuration requires "local_pk" option for HTTPS/WSS server.',
            );
        }

        if (!is_readable($cert)) {
            throw new \InvalidArgumentException(
                sprintf('SSL certificate file is not readable: %s', $cert),
            );
        }

        if (!is_readable($key)) {
            throw new \InvalidArgumentException(
                sprintf('SSL private key file is not readable: %s', $key),
            );
        }

        return [
            'ssl' => [
                'local_cert' => $cert,
                'local_pk' => $key,
            ],
        ];
    }
}
