<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'CrazyGoat\\WorkermanBundle\\Tools\\PhpCsFixer\\' => __DIR__ . '/',
        'PhpCsFixer\\' => 'phar://' . \dirname(__DIR__, 2) . '/vendor/php-cs-fixer/shim/php-cs-fixer.phar/src/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $directory . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }

        return;
    }
});
