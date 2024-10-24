<?php

declare(strict_types=1);

namespace Luzrain\WorkermanBundle\Worker;

use Luzrain\WorkermanBundle\KernelFactory;
use Luzrain\WorkermanBundle\Protocol\Http\Request\SymfonyRequest;
use Luzrain\WorkermanBundle\Utils;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;

final class ServerWorker
{
    protected const PROCESS_TITLE = '[Server]';

    public function __construct(
        KernelFactory $kernelFactory,
        string | null $user,
        string | null $group,
        array $serverConfig,
        $symfonyNative = false,
    ) {
        $listen = strval($serverConfig['listen'] ?? '');
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

        if ($symfonyNative === true) {
            $worker->onConnect = function ($connection): void {
                if ($connection instanceof TcpConnection && $connection->protocol === Http::class) {
                    Http::requestClass(SymfonyRequest::class);
                }
            };
        }

        $worker->onWorkerStart = function (Worker $worker) use ($kernelFactory, $serverConfig): void {
            $serveFiles = $serverConfig['serve_files'] ?? true;
            $worker->log(sprintf('%s "%s" started', $worker->name, $serverConfig['name']));
            $kernel = $kernelFactory->createKernel();
            $kernel->boot();
            $worker->onMessage =
                function (TcpConnection $connection, Request | SymfonyRequest $workermanRequest) use (
                    $serveFiles,
                    $kernel
                ): void {
                    $kernel->getContainer()->get('workerman.http_request_handler')(
                        $connection,
                        $workermanRequest,
                        $serveFiles
                    );
                };
        };
    }
}
