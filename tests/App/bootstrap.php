<?php

declare(strict_types=1);

// Pin the umask for the test process itself (issue #778): in-process kernel
// boots in the suite create var/cache descendants, and a permissive
// invoking-shell umask would leave them world-writable. The daemon is a
// separate process that resets the umask itself (Symfony Runtime and
// Worker::daemonize() both call umask(0)), so its tree is hardened
// post-start in harden_test_var_tree() below.
umask(0077);

include __DIR__ . '/../../vendor/autoload.php';

// PollingMonitorWatcher tests call Utils::reload(reloadAllWorkers: true) which
// sends SIGUSR1 to posix_getppid() — the PHPUnit parent process. Ignore it.
if (\extension_loaded('pcntl') && \defined('SIGUSR1')) {
    \pcntl_async_signals(true);
    \pcntl_signal(\SIGUSR1, \SIG_IGN);
}

\workerman_start();
\harden_test_var_tree();
\register_shutdown_function(\workerman_stop(...));

/**
 * Symfony's Runtime (debug) and Workerman's Worker::daemonize() both reset the
 * process umask to 0, so the daemon's var/cache and var/log trees end up
 * world-writable under a permissive invoking umask (issue #778). The daemon is
 * already running when this returns, so reinforce the modes: directories lose
 * group/other access, files lose group/other write.
 */
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
    foreach (['task_status.log', 'process_start.marker', 'process_error.marker'] as $marker) {
        $path = __DIR__ . '/../../var/' . $marker;
        if (\is_file($path)) {
            \unlink($path);
        }
    }
}
