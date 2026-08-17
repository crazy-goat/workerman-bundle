<?php

declare(strict_types=1);

include __DIR__ . '/../../vendor/autoload.php';

// PollingMonitorWatcher tests call Utils::reload(reloadAllWorkers: true) which
// sends SIGUSR1 to posix_getppid() — the PHPUnit parent process. Ignore it.
if (\extension_loaded('pcntl') && \defined('SIGUSR1')) {
    \pcntl_async_signals(true);
    \pcntl_signal(\SIGUSR1, \SIG_IGN);
}

\workerman_start();
\register_shutdown_function(\workerman_stop(...));

function workerman_create_command(string $command): string
{
    return \sprintf('%s %s/index.php %s', PHP_BINARY, __DIR__, $command);
}

function workerman_create_console_command(string $command): string
{
    return \sprintf('%s %s/console workerman:server %s', PHP_BINARY, __DIR__, $command);
}

function workerman_start(): void
{
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = \proc_open(\workerman_create_command('start -d'), $descriptor, $pipes);

    if (\is_resource($process)) {
        foreach ($pipes as $pipe) {
            \fclose($pipe);
        }
        \proc_close($process);
    }

    // Wait for the daemon to bind its ports instead of a fixed sleep, so a
    // slow start (cold cache, loaded CI runner) does not race the first test.
    \CrazyGoat\WorkermanBundle\Util\Wait::until(
        static function (): bool {
            $sock = @\fsockopen('127.0.0.1', 8888, $errno, $errstr, 0.2);
            if ($sock === false) {
                return false;
            }
            \fclose($sock);

            return true;
        },
        15,
    );
}

function workerman_stop(): void
{
    \shell_exec(\workerman_create_command('stop'));
    @unlink(__DIR__ . '/../../var/task_status.log');
    @unlink(__DIR__ . '/../../var/process_start.marker');
    @unlink(__DIR__ . '/../../var/process_error.marker');
}
