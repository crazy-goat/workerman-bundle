<?php

declare(strict_types=1);

umask(0077);

include __DIR__ . '/../../vendor/autoload.php';

\CrazyGoat\WorkermanBundle\Test\App\TestPorts::applyCacheDir(\dirname(__DIR__, 2));

if (\extension_loaded('pcntl') && \defined('SIGUSR1')) {
    \pcntl_async_signals(true);
    \pcntl_signal(\SIGUSR1, \SIG_IGN);
}

\register_shutdown_function(\workerman_stop(...));
\workerman_start();
\harden_test_var_tree();

function harden_test_var_tree(): void
{
    foreach ([__DIR__ . '/../../var/cache', __DIR__ . '/../../var/log'] as $root) {
        if (!\is_dir($root)) {
            continue;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            $perms = \fileperms($item->getPathname());
            if ($perms === false) {
                continue;
            }

            @\chmod(
                $item->getPathname(),
                $item->isDir() ? $perms & ~0o077 : $perms & ~0o022,
            );
        }

        @\chmod($root, (\fileperms($root) ?: 0) & ~0o077);
    }
}

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

    $host = \CrazyGoat\WorkermanBundle\Test\App\TestPorts::connectHost();
    $port = \CrazyGoat\WorkermanBundle\Test\App\TestPorts::http();

    $started = \CrazyGoat\WorkermanBundle\Util\Wait::until(
        static function () use ($host, $port): bool {
            $sock = @\fsockopen($host, $port, $errno, $errstr, 0.2);
            if ($sock === false) {
                return false;
            }
            \fclose($sock);

            return true;
        },
        15,
    );

    if (!$started) {
        \fwrite(\STDERR, \sprintf(
            "The test daemon did not start on %s:%d.\n"
            . "Check var/log/workerman.log and var/log/workerman.stdout.log.\n",
            $host,
            $port,
        ));
        exit(1);
    }
}

function workerman_stop(): void
{
    \shell_exec(\workerman_create_command('stop'));
    foreach (['task_status.log', 'process_start.marker', 'process_error.marker'] as $marker) {
        $path = __DIR__ . '/../../var/' . $marker;
        if (\is_file($path)) {
            \unlink($path);
        }
    }
}
