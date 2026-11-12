<?php

declare(strict_types=1);

$argv = $_SERVER['argv'] ?? [];

$ports = [];
$host = '127.0.0.1';
$timeoutSeconds = 15;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--timeout=(\d+(?:\.\d+)?)$/', $arg, $m) === 1) {
        $timeoutSeconds = (float) $m[1];
    } elseif (preg_match('/^--host=(.+)$/', $arg, $m) === 1) {
        $host = $m[1];
    } elseif (ctype_digit($arg)) {
        $ports[] = (int) $arg;
    } else {
        fwrite(STDERR, sprintf("Unknown argument: %s\n", $arg));
        exit(2);
    }
}

if ($ports === []) {
    fwrite(STDERR, "Usage: php bin/wait-for-ports.php <port> [<port> ...] [--host=<host>] [--timeout=<seconds>]\n");
    exit(2);
}

if (str_contains($host, ':') && !str_starts_with($host, '[')) {
    $host = '[' . $host . ']';
}

require __DIR__ . '/../vendor/autoload.php';

$check = static function () use ($ports, $host): bool {
    foreach ($ports as $port) {
        $sock = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if ($sock === false) {
            return false;
        }
        fclose($sock);
    }

    return true;
};

$ready = CrazyGoat\WorkermanBundle\Util\Wait::until($check, (int) ceil($timeoutSeconds));

if (!$ready) {
    fwrite(STDERR, sprintf(
        "Daemon did not become ready on %s port(s) %s within %s seconds.\n",
        $host,
        implode(', ', array_map(strval(...), $ports)),
        $timeoutSeconds,
    ));
    exit(1);
}

exit(0);
