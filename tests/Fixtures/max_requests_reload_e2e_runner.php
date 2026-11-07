<?php

declare(strict_types=1);

/**
 * Minimal Workerman HTTP server for the max_requests reload E2E test (#934).
 *
 * A single worker answers every request with its own PID and uses
 * MaxJobsRebootStrategy as the HttpRequestHandler reboot strategy — the same
 * strategy the `reload_strategy.max_requests` config registers. After
 * <requests> handled requests the strategy returns true, the handler calls
 * Utils::reload() (SIGUSR1 to the worker itself) and Workerman replaces the
 * worker, so the next request reports a new PID.
 *
 * argv:
 *   1 = vendor/autoload.php path
 *   2 = listen port
 *   3 = ready file path (written from onWorkerStart)
 *   4 = worker pid file path (rewritten by every worker generation)
 *   5 = work dir for Workerman pid/log/status files
 *   6 = requests before reload (default 3)
 */

/** @var list<string> $argv */

if (($argc ?? 0) < 6) {
    fwrite(STDERR, "Usage: max_requests_reload_e2e_runner.php <autoload> <port> <ready> <worker_pid> <workdir> [requests]\n");
    exit(2);
}

[, $autoload, $portArgument, $readyFile, $workerPidFile, $workDir] = $argv;
$port = (int) $portArgument;
$requests = (int) ($argv[6] ?? 3);

if (!is_file($autoload)) {
    fwrite(STDERR, "autoload not found: {$autoload}\n");
    exit(2);
}
if ($port < 1 || $port > 65535) {
    fwrite(STDERR, "invalid port: {$port}\n");
    exit(2);
}
if ($requests < 1) {
    fwrite(STDERR, "requests must be at least 1, got: {$requests}\n");
    exit(2);
}

require $autoload;

use CrazyGoat\WorkermanBundle\Http\HttpRequestHandler;
use CrazyGoat\WorkermanBundle\Http\Request as BundleRequest;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\MaxJobsRebootStrategy;
use Symfony\Component\DependencyInjection\ContainerInterface;
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

$worker = new Worker(sprintf('http://127.0.0.1:%d', $port));
$worker->name = 'max-requests-reload-e2e';
$worker->count = 1;
$worker->reusePort = false;

// Built per worker, like ServerWorker does, so every replacement worker gets
// its own strategy state instead of inheriting the master's copy.
$handler = null;

$worker->onWorkerStart = static function () use (&$handler, $readyFile, $workerPidFile, $requests): void {
    Http::requestClass(BundleRequest::class);

    $kernel = new class implements KernelInterface {
        public function handle(SymfonyRequest $request, int $type = self::MAIN_REQUEST, bool $catch = true): SymfonyResponse
        {
            return new SymfonyResponse((string) getmypid(), SymfonyResponse::HTTP_OK, ['Content-Type' => 'text/plain']);
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

        public function getContainer(): ContainerInterface
        {
            throw new \RuntimeException('Container not initialized');
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

    $controller = new SymfonyController($kernel, new ResponseConverter([new DefaultResponseStrategy()]));
    $handler = new HttpRequestHandler($controller, new MaxJobsRebootStrategy($requests, 0));

    file_put_contents($workerPidFile, (string) getmypid());
    file_put_contents($readyFile, (string) getmypid());
};

$worker->onMessage = static function (TcpConnection $connection, BundleRequest $request) use (&$handler): void {
    if (!$handler instanceof HttpRequestHandler) {
        $connection->close();

        return;
    }

    $handler($connection, $request);
};

Worker::runAll();
