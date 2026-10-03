<?php

declare(strict_types=1);

/**
 * Minimal Workerman HTTP server for the async signals E2E test (issue #987).
 *
 * Creating a Symfony Console Application turns on pcntl_async_signals(true),
 * like bin/console does before it starts the server. The server is started
 * through MasterWorker::runAll(), like Runner does.
 *
 * argv:
 *   1 = vendor/autoload.php path
 *   2 = listen port
 *   3 = ready file path (written from onWorkerStart)
 *   4 = work dir for Workerman pid/log/status files
 *
 * The route answers "done" after 3 seconds. stop_timeout is 10 seconds.
 */

/** @var list<string> $argv */

if (($argc ?? 0) < 5) {
    fwrite(STDERR, "Usage: async_signals_e2e_runner.php <autoload> <port> <ready> <workdir>\n");
    exit(2);
}

[, $autoload, $port, $readyFile, $workDir] = $argv;

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

// Same as bin/console: this turns on pcntl_async_signals(true).
new Application();

Worker::$pidFile = $workDir . '/workerman.pid';
Worker::$logFile = $workDir . '/workerman.log';
Worker::$statusFile = $workDir . '/workerman.status';
Worker::$stdoutFile = $workDir . '/stdout.log';
Worker::$daemonize = false;
Worker::$command = 'start';
Worker::$stopTimeout = 10;
// The bug shows on the Select loop only.
Worker::$eventLoopClass = Select::class;

$worker = new Worker(sprintf('http://127.0.0.1:%d', (int) $port));
$worker->name = 'async-signals-e2e';
$worker->count = 1;
$worker->reusePort = false;
$worker->onWorkerStart = static function () use ($readyFile): void {
    file_put_contents($readyFile, (string) getmypid());
};
$worker->onMessage = static function (TcpConnection $connection, Request $request): void {
    sleep(3);
    $connection->send('done');
};

MasterWorker::runAll();
