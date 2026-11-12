#!/usr/bin/env php
<?php

declare(strict_types=1);

use CrazyGoat\WorkermanBundle\Test\App\TestPorts;

require __DIR__ . '/../vendor/autoload.php';

$args = $_SERVER['argv'] ?? [];
$extra = \is_array($args) ? \array_slice($args, 1) : [];

$_SERVER['argv'] = [
    'wait-for-ports.php',
    ...\array_map(\strval(...), TestPorts::all()),
    '--host=' . TestPorts::connectHost(),
    ...$extra,
];

require __DIR__ . '/wait-for-ports.php';
