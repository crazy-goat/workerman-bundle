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
            self::log(\sprintf('Unable to write master fingerprint: %s', $e->getMessage()));
        }
    }
}
