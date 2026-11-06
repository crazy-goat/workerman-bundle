<?php

declare(strict_types=1);

use CrazyGoat\WorkermanBundle\Worker\MasterWorker;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

$stdoutFile = $argv[1] ?? '';
$reportFile = $argv[2] ?? '';

Worker::$daemonize = true;
Worker::$stdoutFile = $stdoutFile;
ini_set('error_log', '');

MasterWorker::resetStd();

if ($reportFile !== '') {
    $target = @readlink('/proc/self/fd/2');
    file_put_contents($reportFile, json_encode([
        'fd2' => $target === false ? 'missing' : $target,
        'error_log' => ini_get('error_log'),
    ], JSON_THROW_ON_ERROR));
}

// In daemon mode Workerman closed STDOUT/STDERR. The bundle keeps fd 2 on
// stdout_file, so a php://stderr writer (Monolog's stream handler) must land
// there instead of writing to an eventpoll fd (issue #990).
file_put_contents('php://stderr', "stderr-line\n");
error_log('error-log-line');

exit(0);
