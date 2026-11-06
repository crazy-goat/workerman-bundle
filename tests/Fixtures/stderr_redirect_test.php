<?php

declare(strict_types=1);

use CrazyGoat\WorkermanBundle\Worker\MasterWorker;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

$stdoutFile = $argv[1] ?? '';
$reportFile = $argv[2] ?? '';

Worker::$daemonize = true;
Worker::$stdoutFile = $stdoutFile;

MasterWorker::resetStd();

if ($reportFile !== '') {
    $target = @readlink('/proc/self/fd/2');
    file_put_contents($reportFile, $target === false ? 'missing' : $target);
}

// In daemon mode Workerman closed STDOUT/STDERR. The bundle keeps fd 2 on
// stdout_file, so a php://stderr writer (Monolog's stream handler) must land
// there instead of writing to an eventpoll fd (issue #990).
file_put_contents('php://stderr', "stderr-line\n");

exit(0);
