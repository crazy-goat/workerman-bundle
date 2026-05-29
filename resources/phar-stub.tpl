#!/usr/bin/env php
<?php

define('IN_PHAR', true);
Phar::mapPhar('__PHAR_ALIAS__');

$runtimeDir = isset($_SERVER['WORKERMAN_RUNTIME_DIR']) && $_SERVER['WORKERMAN_RUNTIME_DIR'] !== ''
    ? rtrim((string) $_SERVER['WORKERMAN_RUNTIME_DIR'], '/')
    : dirname(Phar::running(false));

$_SERVER['APP_RUNTIME'] = '__RUNTIME_CLASS__';
$_ENV['APP_CACHE_DIR'] = $runtimeDir . '/var/cache';
$_ENV['APP_LOG_DIR'] = $runtimeDir . '/var/log';

foreach (['/var/cache', '/var/log', '/var/run'] as $sub) {
    $dir = $runtimeDir . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        fwrite(STDERR, sprintf('Unable to create runtime directory "%s". Set WORKERMAN_RUNTIME_DIR to a writable path.' . PHP_EOL, $dir));
        exit(1);
    }
}

// Load external .env if it exists
if (file_exists($runtimeDir . '/.env')) {
    if (class_exists('Symfony\Component\Dotenv\Dotenv')) {
        (new Symfony\Component\Dotenv\Dotenv())->load($runtimeDir . '/.env');
    }
}

require 'phar://__PHAR_ALIAS__/vendor/autoload.php';

$env = $_SERVER['APP_ENV'] ?? '__APP_ENV__';
$debug = (bool)($_SERVER['APP_DEBUG'] ?? false);

$kernel = new __KERNEL_CLASS__($env, $debug);
$application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$application->run(new Symfony\Component\Console\Input\ArgvInput());

__HALT_COMPILER();
