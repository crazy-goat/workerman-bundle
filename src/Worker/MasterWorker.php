<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Worker;

use CrazyGoat\WorkermanBundle\MasterFingerprint;
use Workerman\Timer;
use Workerman\Worker;

final class MasterWorker extends Worker
{
    /** @var list<resource> */
    private static array $stdioStreams = [];

    private static bool $gracefulSigterm = false;

    private static int $gracefulStopTimeout = 30;

    public static function configureGracefulSigterm(bool $enabled, int $timeout): void
    {
        self::$gracefulSigterm = $enabled;
        self::$gracefulStopTimeout = max(1, $timeout);
    }

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

        if (!is_resource(self::$outputStream)) {
            $sink = @fopen('/dev/null', 'w');
            if ($sink !== false) {
                self::$stdioStreams[] = $sink;
            }
        }

        $stream = @fopen(self::$stdoutFile, 'a');
        $stderrTarget = self::$stdoutFile;
        if ($stream === false) {
            $stream = @fopen('/dev/null', 'w');
            $stderrTarget = '/dev/null';
        }

        if ($stream !== false) {
            self::$stdioStreams[] = $stream;
        }

        if (ini_get('error_log') === '') {
            @ini_set('error_log', $stderrTarget);
        }
    }

    public static function runAll(): void
    {
        pcntl_async_signals(false);

        parent::runAll();
    }

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
