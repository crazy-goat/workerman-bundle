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
use Symfony\Component\HttpKernel\KernelInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Timer;
use Workerman\Worker;

final readonly class ServerWorker
{
    private const PROCESS_TITLE = '[Server]';

    /**
     * @param array{
     *     name: string,
     *     listen?: string|null,
     *     local_cert?: string|null,
     *     local_pk?: string|null,
     *     processes?: int|null,
     *     reuse_port?: bool,
     *     body_size_cap?: int|null,
     *     serve_files?: bool,
     *     root_dir?: string|null,
     *     middlewares?: list<string>,
     *     static_files?: array{allowed_extensions?: list<string>},
     * } $serverConfig
     */
    public function __construct(
        KernelFactory $kernelFactory,
        ?string       $user,
        ?string       $group,
        array         $serverConfig,
        int           $connectionTimeout = 120,
        int           $keepaliveTimeout = 30,
    ) {
        $listen = $serverConfig['listen'] ?? '';
        assert(is_string($listen));

        $scheme = ListenScheme::fromListen($listen);
        $listen = str_replace($scheme->value . '://', $scheme->workermanPrefix(), $listen);
        $transport = $scheme->transport();
        $context = $scheme->requiresSslContext() ? $this->createSslContext($serverConfig) : [];

        $worker = new Worker($listen, $context);
        $worker->name = sprintf('%s %s', self::PROCESS_TITLE, $serverConfig['name']);
        $worker->user = $user ?? '';
        $worker->group = $group ?? '';
        $worker->count = $serverConfig['processes'] ?? Utils::cpuCount() * 2;
        $worker->transport = $transport;
        $worker->reusePort = (bool) ($serverConfig['reuse_port'] ?? false);

        $bodySizeCap = $serverConfig['body_size_cap'] ?? null;

        $worker->onConnect = function (TcpConnection $connection) use ($connectionTimeout, $bodySizeCap): void {
            if ($bodySizeCap !== null) {
                $connection->maxPackageSize = $bodySizeCap;
            }

            $connection->context ??= new \stdClass();
            $connection->context->connectionTimerId = Timer::add(
                $connectionTimeout,
                static function () use ($connection): void {
                    $connection->close();
                },
                [],
                false,
            );

            // Defence-in-depth backstop: if a throwable escapes
            // HttpRequestHandler's own try/catch (e.g. from a future
            // throw site added after this fix, or from a Workerman
            // callback that never passes through the handler), close
            // the connection cleanly instead of letting Workerman call
            // Worker::stopAll(250) which terminates the whole worker
            // process. See issue #577.
            $connection->errorHandler = static function (\Throwable $e) use ($connection): void {
                // Log unconditionally — an escaped throwable should never
                // happen and operators need visibility. Use error_log()
                // because the PSR-3 logger is not easily reachable here.
                error_log(sprintf(
                    'Unhandled throwable escaped HttpRequestHandler; closing connection: %s in %s:%d',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                ));

                // Clean up per-connection timers so they don't keep
                // firing on a defunct connection after close().
                if (isset($connection->context->keepaliveTimerId)) {
                    Timer::del($connection->context->keepaliveTimerId);
                    unset($connection->context->keepaliveTimerId);
                }
                if (isset($connection->context->connectionTimerId)) {
                    Timer::del($connection->context->connectionTimerId);
                    unset($connection->context->connectionTimerId);
                }

                $connection->close();
            };
        };

        $worker->onWorkerStart = function (Worker $worker) use ($kernelFactory, $serverConfig, $keepaliveTimeout): void {
            Http::requestClass(Request::class);

            $serveFiles = $serverConfig['serve_files'] ?? false;
            $rootDir = $serveFiles ? $serverConfig['root_dir'] ?? null : null;

            $worker->log(sprintf('%s "%s" started', $worker->name, $serverConfig['name']));
            $kernel = $kernelFactory->createKernel();
            $kernel->boot();

            $handler = $this->configureHandler($kernel, $serverConfig, $rootDir);

            $worker->onMessage = function (TcpConnection $connection, Request $request) use ($handler, $keepaliveTimeout): void {
                if (isset($connection->context->connectionTimerId)) {
                    Timer::del($connection->context->connectionTimerId);
                    unset($connection->context->connectionTimerId);
                }

                if (isset($connection->context->keepaliveTimerId)) {
                    Timer::del($connection->context->keepaliveTimerId);
                    unset($connection->context->keepaliveTimerId);
                }

                try {
                    $handler($connection, $request);
                } finally {
                    $request->resetHeaders();
                }

                if ($keepaliveTimeout > 0) {
                    $connection->context ??= new \stdClass();
                    $connection->context->keepaliveTimerId = Timer::add(
                        $keepaliveTimeout,
                        static function () use ($connection): void {
                            $connection->close();
                        },
                        [],
                        false,
                    );
                }
            };
        };
    }

    /**
     * Boot kernel, resolve the request handler and middlewares, and configure the handler.
     *
     * @param array{
     *     name: string,
     *     listen?: string|null,
     *     local_cert?: string|null,
     *     local_pk?: string|null,
     *     processes?: int|null,
     *     reuse_port?: bool,
     *     body_size_cap?: int|null,
     *     serve_files?: bool,
     *     root_dir?: string|null,
     *     middlewares?: list<string>,
     *     static_files?: array{allowed_extensions?: list<string>},
     * } $serverConfig
     *
     * @return callable The fully configured request handler
     */
    private function configureHandler(
        KernelInterface $kernel,
        array           $serverConfig,
        ?string         $rootDir,
    ): callable {
        $callable = $kernel->getContainer()->get('workerman.http_request_handler');
        assert(is_callable($callable));

        $middlewares = array_map(function (string $middleware) use ($kernel): MiddlewareInterface {
            $service = $kernel->getContainer()->get($middleware);
            if (!$service instanceof MiddlewareInterface) {
                throw new InvalidMiddlewareException(sprintf('Service "%s" must implement "%s"', $middleware, MiddlewareInterface::class));
            }

            return $service;
        }, $serverConfig['middlewares'] ?? []);

        if ($callable instanceof StaticFileHandlerInterface) {
            $callable->withStaticFileConfig($serverConfig['static_files'] ?? []);
            $callable->withRootDirectory($rootDir);
        }

        if ($callable instanceof MiddlewareDispatchInterface && $middlewares !== []) {
            $callable->withMiddlewares(...$middlewares);
        }

        return $callable;
    }

    /**
     * @param array{
     *     name: string,
     *     listen?: string|null,
     *     local_cert?: string|null,
     *     local_pk?: string|null,
     *     processes?: int|null,
     *     reuse_port?: bool,
     *     body_size_cap?: int|null,
     *     serve_files?: bool,
     *     root_dir?: string|null,
     *     middlewares?: list<string>,
     *     static_files?: array{allowed_extensions?: list<string>},
     * } $serverConfig
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

        if (is_link($cert)) {
            throw new \InvalidArgumentException(
                sprintf('SSL certificate path must not be a symlink: %s', $cert),
            );
        }

        if (is_link($key)) {
            throw new \InvalidArgumentException(
                sprintf('SSL private key path must not be a symlink: %s', $key),
            );
        }

        if (!is_file($cert)) {
            throw new \InvalidArgumentException(
                sprintf('SSL certificate path must be a regular file: %s', $cert),
            );
        }

        if (!is_file($key)) {
            throw new \InvalidArgumentException(
                sprintf('SSL private key path must be a regular file: %s', $key),
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
