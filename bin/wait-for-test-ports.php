#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Waits for the three test daemon ports resolved by
 * {@see \CrazyGoat\WorkermanBundle\Test\App\TestPorts}.
 *
 * The composer `test` and `test:coverage` scripts call this instead of
 * spelling the ports out in composer.json: the WMB_PORT_* overrides and their
 * defaults stay in one place and do not depend on shell expansion.
 *
 * Usage: php bin/wait-for-test-ports.php [--timeout=<seconds>]
 */

use CrazyGoat\WorkermanBundle\Test\App\TestPorts;

require __DIR__ . '/../vendor/autoload.php';

$args = $_SERVER['argv'] ?? [];
$extra = \is_array($args) ? \array_slice($args, 1) : [];

$_SERVER['argv'] = [
    'wait-for-ports.php',
    ...\array_map(\strval(...), TestPorts::all()),
    ...$extra,
];

require __DIR__ . '/wait-for-ports.php';
