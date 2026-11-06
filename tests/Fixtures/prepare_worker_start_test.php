<?php

declare(strict_types=1);

use CrazyGoat\WorkermanBundle\Command\ServerAction;
use CrazyGoat\WorkermanBundle\ServerManager;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

$manager = (new ReflectionClass(ServerManager::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(ServerManager::class, 'prepareWorkerStart');

$method->invoke($manager, ServerAction::RESTART, true, true);
$restart = ['daemon' => Worker::$daemonize, 'command' => Worker::$command];

$method->invoke($manager, ServerAction::START, false, true);
$start = ['daemon' => Worker::$daemonize, 'command' => Worker::$command];

echo json_encode([$restart, $start], JSON_THROW_ON_ERROR);
