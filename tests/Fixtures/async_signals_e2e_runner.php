<?php

declare(strict_types=1);

/** @var list<string> $argv */

if (($argc ?? 0) < 5) {
    fwrite(STDERR, "Usage: async_signals_e2e_runner.php <autoload> <port> <ready> <workdir> [graceful] [stop_timeout] [graceful_stop_timeout]\n");
    exit(2);
}

[, $autoload, $port, $readyFile, $workDir] = $argv;
$gracefulSigterm = ($argv[5] ?? '0') === '1';
$stopTimeout = (int) ($argv[6] ?? 10);
$gracefulStopTimeout = (int) ($argv[7] ?? 30);
$blockingWorkerEnabled = ($argv[8] ?? '0') === '1';

require $autoload;

use CrazyGoat\WorkermanBundle\Worker\MasterWorker;
use Symfony\Component\Console\Application;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;

if (!is_dir($workDir) && !mkdir($workDir, 0700, true) && !is_dir($workDir)) {
    fwrite(STDERR, "cannot create workdir: {$workDir}\n");
    exit(2);
}

new Application();

Worker::$pidFile = $workDir . '/workerman.pid';
Worker::$logFile = $workDir . '/workerman.log';
Worker::$statusFile = $workDir . '/workerman.status';
Worker::$stdoutFile = $workDir . '/stdout.log';
Worker::$daemonize = false;
Worker::$command = 'start';
Worker::$stopTimeout = $stopTimeout;
Worker::$eventLoopClass = Select::class;

MasterWorker::configureGracefulSigterm($gracefulSigterm, $gracefulStopTimeout);

$worker = new Worker(sprintf('http://127.0.0.1:%d', (int) $port));
$worker->name = 'async-signals-e2e';
$worker->count = 1;
$worker->reusePort = false;
$worker->onWorkerStart = static function () use ($readyFile): void {
    file_put_contents($readyFile, (string) getmypid());
};
if ($blockingWorkerEnabled) {
    $blockingWorker = new Worker();
    $blockingWorker->name = 'blocking-e2e';
    $blockingWorker->count = 1;
    $blockingWorker->onWorkerStart = static function (): void {
        for (;;) {
            usleep(100_000);
        }
    };
}

$worker->onMessage = static function (TcpConnection $connection, Request $request): void {
    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline) {
        usleep(10_000);
    }
    $connection->send('done');
};

MasterWorker::runAll();
