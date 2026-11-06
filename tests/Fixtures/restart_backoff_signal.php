<?php

declare(strict_types=1);

use CrazyGoat\WorkermanBundle\Worker\RestartBackoff;

require __DIR__ . '/../../vendor/autoload.php';

$runtimeDir = $argv[1] ?? '';
$readyFile = $argv[2] ?? '';

pcntl_async_signals(false);
// Ignore SIGTERM before the backoff runs: only the temporary handler inside
// the wait can end the child. Without that handler the child sleeps 30 s and
// the test fails on its timeout.
pcntl_signal(SIGTERM, SIG_IGN, false);

$backoff = new RestartBackoff(
    runtimeDir: $runtimeDir,
    delay: 30,
    maxDelay: 60,
    maxFailures: 10,
    healthyAfter: 30,
);

$noop = static function (string $message): void {
};

// The first start records a failure without waiting; the second start would
// wait 30 seconds unless a stop signal interrupts the wait.
$backoff->beforeStart('slot', 'Process', 'worker', $noop);
file_put_contents($readyFile, 'ready');
$backoff->beforeStart('slot', 'Process', 'worker', $noop);

exit(2);
