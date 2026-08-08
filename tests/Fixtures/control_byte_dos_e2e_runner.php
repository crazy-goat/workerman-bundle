<?php

declare(strict_types=1);

/**
 * Minimal Workerman HTTP worker for issue #577 E2E acceptance criteria.
 *
 * argv:
 *   1 = vendor/autoload.php path
 *   2 = listen port (int)
 *   3 = mode: "handler" | "backstop" | "cache576"
 *   4 = ready file path (written from onWorkerStart)
 *   5 = worker pid file path (written from onWorkerStart with getmypid())
 *   6 = work dir for Workerman pid/log/status files
 *
 * handler  — full production path: HttpRequestHandler try/catch + errorHandler
 * backstop — no handler try; RequestConverter throws out of onMessage into
 *            $connection->errorHandler (proves backstop independence)
 */

/** @var list<string> $argv */

if (($argc ?? 0) < 7) {
    fwrite(STDERR, "Usage: control_byte_dos_e2e_runner.php <autoload> <port> <mode> <ready> <pid> <workdir>\n");
    exit(2);
}

$autoload = $argv[1];
$port = (int) $argv[2];
$mode = $argv[3];
$readyFile = $argv[4];
$pidFile = $argv[5];
$workDir = $argv[6];

if (!is_file($autoload)) {
    fwrite(STDERR, "autoload not found: {$autoload}\n");
    exit(2);
}
if ($port < 1 || $port > 65535) {
    fwrite(STDERR, "invalid port: {$port}\n");
    exit(2);
}
if (!in_array($mode, ['handler', 'backstop', 'cache576'], true)) {
    fwrite(STDERR, "mode must be handler|backstop|cache576, got: {$mode}\n");
    exit(2);
}

require $autoload;

use CrazyGoat\WorkermanBundle\DTO\RequestConverter;
use CrazyGoat\WorkermanBundle\Http\HttpRequestHandler;
use CrazyGoat\WorkermanBundle\Http\Request as BundleRequest;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Worker;

if (!is_dir($workDir) && !mkdir($workDir, 0700, true) && !is_dir($workDir)) {
    fwrite(STDERR, "cannot create workdir: {$workDir}\n");
    exit(2);
}

Worker::$pidFile = $workDir . '/workerman.pid';
Worker::$logFile = $workDir . '/workerman.log';
Worker::$statusFile = $workDir . '/workerman.status';
Worker::$daemonize = false;
Worker::$stdoutFile = $workDir . '/stdout.log';
// Workerman parses CLI argv for start/stop/... — force start without relying
// on script argv (we already consumed it for our own options).
Worker::$command = 'start';

$kernel = new class implements KernelInterface {
    private ?\Symfony\Component\DependencyInjection\ContainerInterface $container = null;

    public function setContainer(\Symfony\Component\DependencyInjection\ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function handle(SymfonyRequest $request, int $type = self::MAIN_REQUEST, bool $catch = true): SymfonyResponse
    {
        return new SymfonyResponse(
            ($request->headers->get('x-request-processed', 'missing')) . '|'
            . ($request->headers->get('x-forwarded-for', 'missing')),
            SymfonyResponse::HTTP_OK,
        );
    }

    public function boot(): void
    {
    }

    public function shutdown(): void
    {
    }

    public function getBundles(): array
    {
        return [];
    }

    public function getBundle(string $name, bool $throw = true): \Symfony\Component\HttpKernel\Bundle\BundleInterface
    {
        throw new \RuntimeException('n/a');
    }

    public function locateResource(string $name): string
    {
        return '';
    }

    public function getEnvironment(): string
    {
        return 'test';
    }

    public function isDebug(): bool
    {
        return false;
    }

    public function getProjectDir(): string
    {
        return sys_get_temp_dir();
    }

    public function getContainer(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        if (!$this->container instanceof \Symfony\Component\DependencyInjection\ContainerInterface) {
            throw new \RuntimeException('Container not initialized');
        }

        return $this->container;
    }

    public function getStartTime(): float
    {
        return 0.0;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir();
    }

    public function getBuildDir(): string
    {
        return sys_get_temp_dir();
    }

    public function getShareDir(): ?string
    {
        return null;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir();
    }

    public function getCharset(): string
    {
        return 'UTF-8';
    }

    public function registerBundles(): iterable
    {
        return [];
    }

    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
    }
};

$rebootStrategy = new class implements RebootStrategyInterface {
    public function shouldReboot(): bool
    {
        return false;
    }

    public function needsPeakMemory(): bool
    {
        return false;
    }
};

$responseConverter = new ResponseConverter([new DefaultResponseStrategy()]);
$controller = new SymfonyController($kernel, $responseConverter);
$handler = new HttpRequestHandler($controller, $rebootStrategy);
$handler->withMiddlewares(new class implements MiddlewareInterface {
    /** @var array<int, int> */
    private static array $requestsBySize = [];

    public function __invoke(BundleRequest $request, callable $next): \Workerman\Protocols\Http\Response
    {
        $size = strlen($request->rawHead());
        $requests = self::$requestsBySize[$size] ?? 0;
        self::$requestsBySize[$size] = $requests + 1;
        if ($requests === 0) {
            $request->setHeader('X-Request-Processed', 'yes');
            $request->setHeader('X-Forwarded-For', '198.51.100.99');
        }

        return $next($request);
    }
});

if ($mode === 'cache576') {
    $container = new ContainerBuilder();
    $container->set('workerman.http_request_handler', $handler);
    $kernel->setContainer($container);
    $kernelFactory = new \CrazyGoat\WorkermanBundle\KernelFactory(static fn(): KernelInterface => $kernel, []);
    new \CrazyGoat\WorkermanBundle\Worker\ServerWorker(
        $kernelFactory,
        null,
        null,
        [
            'name' => 'sec576-e2e',
            'listen' => sprintf('http://127.0.0.1:%d', $port),
            'processes' => 1,
            'reuse_port' => false,
        ],
    );
    file_put_contents($pidFile, (string) getmypid());
    file_put_contents($readyFile, sprintf("ready pid=%d mode=%s\\n", getmypid(), $mode));
    Worker::runAll();
    exit(0);
}

$worker = new Worker(sprintf('http://127.0.0.1:%d', $port));
$worker->name = 'sec577-e2e';
$worker->count = 1;
$worker->reusePort = false;

// Mirror ServerWorker::onConnect errorHandler backstop (issue #577).
$worker->onConnect = static function (TcpConnection $connection): void {
    $connection->context ??= new \stdClass();
    $connection->errorHandler = static function (\Throwable $e) use ($connection): void {
        error_log(sprintf(
            'Unhandled throwable escaped HttpRequestHandler; closing connection: %s in %s:%d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));
        $connection->close();
    };
};

$worker->onWorkerStart = static function () use ($pidFile, $readyFile, $mode): void {
    file_put_contents($pidFile, (string) getmypid());
    file_put_contents($readyFile, sprintf("ready pid=%d mode=%s\n", getmypid(), $mode));
    Http::requestClass(BundleRequest::class);
};

if ($mode === 'handler') {
    $worker->onMessage = static function (TcpConnection $connection, BundleRequest $request) use ($handler): void {
        $handler($connection, $request);
    };
} else {
    // Backstop-only: throwable from RequestConverter escapes onMessage into
    // TcpConnection::error() → $connection->errorHandler. No handler try.
    $worker->onMessage = static function (TcpConnection $connection, BundleRequest $request): void {
        RequestConverter::toSymfonyRequest($request);
        $connection->send("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
        $connection->close();
    };
}

Worker::runAll();
