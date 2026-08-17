<?php

declare(strict_types=1);

/**
 * Polls one or more TCP ports until each accepts a connection or a
 * timeout elapses, then exits non-zero with a clear message.
 *
 * Replaces the fixed-duration `sleep 1` in the `composer test` /
 * `composer test:coverage` scripts: if the test daemon needs longer
 * than one second to bind its ports (cold cache, slow CI runner), the
 * readiness check waits for it instead of racing ahead and failing
 * every network-dependent test with a misleading connection error.
 *
 * Uses {@see CrazyGoat\WorkermanBundle\Util\Wait::until()} for
 * exponential backoff — a daemon that is already ready costs one
 * check, a slow one is observed within the backoff cap.
 *
 * Usage: php bin/wait-for-ports.php <port> [<port> ...] [--timeout=<seconds>]
 *
 * Exit codes:
 *   0 = all ports accept connections
 *   1 = at least one port did not become ready within the timeout
 *   2 = invalid usage
 */

$argv = $_SERVER['argv'] ?? [];
$argc = $_SERVER['argc'] ?? 0;

$ports = [];
$timeoutSeconds = 15;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--timeout=(\d+(?:\.\d+)?)$/', $arg, $m) === 1) {
        $timeoutSeconds = (float) $m[1];
    } elseif (ctype_digit($arg)) {
        $ports[] = (int) $arg;
    } else {
        fwrite(STDERR, sprintf("Unknown argument: %s\n", $arg));
        exit(2);
    }
}

if ($ports === []) {
    fwrite(STDERR, "Usage: php bin/wait-for-ports.php <port> [<port> ...] [--timeout=<seconds>]\n");
    exit(2);
}

require __DIR__ . '/../vendor/autoload.php';

$check = static function () use ($ports): bool {
    foreach ($ports as $port) {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($sock === false) {
            return false;
        }
        fclose($sock);
    }

    return true;
};

$ready = CrazyGoat\WorkermanBundle\Util\Wait::until($check, (int) $timeoutSeconds);

if (!$ready) {
    fwrite(STDERR, sprintf(
        "Daemon did not become ready on port(s) %s within %s seconds.\n",
        implode(', ', array_map(strval(...), $ports)),
        $timeoutSeconds,
    ));
    exit(1);
}

exit(0);
