<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Worker;

use CrazyGoat\WorkermanBundle\MasterFingerprint;
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
 */
final class MasterWorker extends Worker
{
    /** Keeps fd 2 open on the stdout file for the lifetime of the process. */
    private static mixed $stderrStream = null;

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
        if (is_resource(self::$stderrStream)) {
            @fclose(self::$stderrStream);
            self::$stderrStream = null;
        }

        parent::resetStd();

        if (!self::$daemonize || DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        $stream = @fopen(self::$stdoutFile, 'a');
        if ($stream === false) {
            // Keep fd 2 away from the event loop even when the configured
            // stdout file is not writable.
            $stream = @fopen('/dev/null', 'w');
        }

        if ($stream !== false) {
            self::$stderrStream = $stream;
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
