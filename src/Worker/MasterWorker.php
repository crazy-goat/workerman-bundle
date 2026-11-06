<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Worker;

use CrazyGoat\WorkermanBundle\MasterFingerprint;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Workerman Worker subclass that records the master fingerprint in daemon mode.
 *
 * In daemon mode (`start -d`), `Worker::daemonize()` forks twice and the
 * launcher process exits, so `ServerManager` cannot capture the master PID
 * before `Runner::run()` is invoked. The PID file, however, is written by the
 * master process itself via `Worker::saveMasterPid()` — which is invoked
 * through late static binding from `Worker::runAll()`. By subclassing
 * `Worker` and running `MasterWorker::runAll()`, this bundle intercepts that
 * call and records a fingerprint for the real master immediately after its
 * PID is known.
 *
 * This removes the daemon-mode verification gap described in issue #584,
 * where daemonised deployments relied on the weak `/proc/$pid/cmdline`
 * substring fallback that accepted any process whose command line contained
 * the substring "php".
 *
 * It also keeps file descriptor 2 on `stdout_file` in daemon mode, so
 * `php://stderr` logging does not write to an eventpoll fd (issue #990).
 */
final class MasterWorker extends Worker
{
    /** @var list<resource> Extra stdio handles kept open for the process lifetime. */
    private static array $stdioStreams = [];

    private static bool $gracefulSigterm = false;

    private static int $gracefulStopTimeout = 30;

    /**
     * Configure the SIGTERM handling before runAll().
     *
     * When enabled, SIGTERM in the master is a graceful stop (like SIGQUIT)
     * with a SIGKILL backstop after $timeout seconds. Docker and Kubernetes
     * send SIGTERM; the default keeps Workerman's fast stop.
     */
    public static function configureGracefulSigterm(bool $enabled, int $timeout): void
    {
        self::$gracefulSigterm = $enabled;
        self::$gracefulStopTimeout = max(1, $timeout);
    }

    /**
     * Treat SIGTERM like SIGQUIT when graceful_sigterm is enabled, and give
     * every graceful stop (SIGQUIT too) a SIGKILL backstop.
     *
     * Workerman's graceful stop has no time limit, so a keep-alive connection
     * or a supervised process that never dispatches SIGQUIT can block it
     * forever. After graceful_stop_timeout the master kills the workers.
     */
    public static function signalHandler(int $signal, mixed ...$args): void
    {
        $gracefulTerm = $signal === \SIGTERM && self::$gracefulSigterm;
        if (($signal !== \SIGQUIT && !$gracefulTerm) || self::$masterPid !== \posix_getpid()) {
            parent::signalHandler($signal, ...$args);

            return;
        }

        self::$gracefulStop = true;
        self::stopAll(0, $signal === \SIGQUIT ? 'received signal SIGQUIT' : 'received signal SIGTERM (graceful)');

        Timer::add(self::$gracefulStopTimeout, static function (): void {
            foreach (self::getAllWorkerPids() as $pid) {
                @\posix_kill($pid, \SIGKILL);
            }
        }, [], false);
    }

    /**
     * Keep file descriptor 2 on the stdout file in daemon mode.
     *
     * Workerman's resetStd() closes STDOUT and STDERR and reopens only the
     * stdout file (fd 1). The event loop then takes fd 2, so a Monolog
     * php://stderr handler writes to an eventpoll fd and fails with EINVAL
     * (issue #990). Open the stdout file again here, before the event loop is
     * created, so fd 2 stays a writable stream. php://stderr then writes to
     * stdout_file instead of breaking requests.
     */
    public static function resetStd(): void
    {
        foreach (self::$stdioStreams as $stream) {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }
        self::$stdioStreams = [];

        parent::resetStd();

        if (!self::$daemonize || DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        // If the parent could not open stdout_file, fd 1 is free too: the
        // first handle would land on fd 1 and leave fd 2 to the event loop.
        // Occupy fd 1 first in that case, then open the stderr handle.
        if (!is_resource(self::$outputStream)) {
            $sink = @fopen('/dev/null', 'w');
            if ($sink !== false) {
                self::$stdioStreams[] = $sink;
            }
        }

        $stream = @fopen(self::$stdoutFile, 'a');
        $stderrTarget = self::$stdoutFile;
        if ($stream === false) {
            // Keep fd 2 away from the event loop even when the configured
            // stdout file is not writable.
            $stream = @fopen('/dev/null', 'w');
            $stderrTarget = '/dev/null';
        }

        if ($stream !== false) {
            self::$stdioStreams[] = $stream;
        }

        // Keep error_log() working even when fd 2 does not point at
        // stdout_file (handle reuse, Windows, or a closed stderr). Only an
        // empty ini setting is replaced; a user setting wins (issue #917).
        if (ini_get('error_log') === '') {
            @ini_set('error_log', $stderrTarget);
        }
    }

    /**
     * Turn async signals off before the master and the workers start.
     *
     * Symfony Console turns on `pcntl_async_signals(true)` and the forked
     * workers inherit it. A stop or reload signal then runs the Workerman
     * handler in the middle of a request and cuts it, so `stop_timeout` does
     * not help (issue #987). Plain Workerman never turns async signals on:
     * the event loop handles the signal after the request.
     */
    public static function runAll(): void
    {
        pcntl_async_signals(false);

        parent::runAll();
    }

    /**
     * Write the master PID, then record its fingerprint.
     *
     * `parent::saveMasterPid()` persists the real master PID (this is the
     * process that survives daemonize). The fingerprint is captured from
     * the current process, so PID, start time, and UID always describe the
     * actual master — even after the double fork of daemon mode.
     *
     * A failure to write the fingerprint is logged but must not abort the
     * start sequence: `ProcessInspector` then falls back to the strict
     * cmdline check instead of the fingerprint check.
     */
    protected static function saveMasterPid(): void
    {
        parent::saveMasterPid();

        $pid = self::$masterPid;
        $pidFile = self::$pidFile;

        if ($pid <= 0 || $pidFile === '') {
            return;
        }

        try {
            MasterFingerprint::capture()->writeTo($pidFile . '.fingerprint');
        } catch (\Throwable $e) {
            self::log('Unable to write master fingerprint: ' . $e->getMessage());
        }
    }
}
