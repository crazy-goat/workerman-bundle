<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\MasterFingerprint;
use CrazyGoat\WorkermanBundle\Worker\MasterWorker;
use PHPUnit\Framework\TestCase;
use Workerman\Worker;

/**
 * Tests for MasterWorker: the Workerman subclass that records the master
 * fingerprint in daemon mode (issue #584).
 */
final class MasterWorkerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/workerman_master_worker_' . uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    /**
     * Regression test for issue #584: `saveMasterPid()` must write the
     * PID file AND a fingerprint describing the current (master) process.
     *
     * `parent::saveMasterPid()` persists the PID; the override must then
     * record a fingerprint of the real master — this is what closes the
     * daemon-mode gap where no fingerprint was ever written.
     */
    public function testSaveMasterPidWritesPidFileAndFingerprint(): void
    {
        $pidFile = $this->tmpDir . '/workerman.pid';

        $masterPidProp = new \ReflectionProperty(MasterWorker::class, 'masterPid');
        $previousPidFile = Worker::$pidFile;
        $previousMasterPid = $masterPidProp->getValue();

        try {
            Worker::$pidFile = $pidFile;
            $masterPidProp->setValue(null, (int) \getmypid());

            $method = new \ReflectionMethod(MasterWorker::class, 'saveMasterPid');
            $method->invoke(null);

            self::assertFileExists($pidFile, 'PID file must be written by parent::saveMasterPid()');
            self::assertSame((string) \getmypid(), (string) file_get_contents($pidFile));

            $fingerprint = MasterFingerprint::readFrom($pidFile . '.fingerprint');
            self::assertNotNull($fingerprint, 'Fingerprint must be written by MasterWorker::saveMasterPid()');
            self::assertSame((int) \getmypid(), $fingerprint->pid, 'Fingerprint PID must be the master PID');
            self::assertSame(\posix_getuid(), $fingerprint->uid, 'Fingerprint UID must be the master UID');
        } finally {
            Worker::$pidFile = $previousPidFile;
            $masterPidProp->setValue(null, $previousMasterPid);
        }
    }

    /**
     * Regression test for issue #584: a fingerprint write failure must
     * not abort the master start sequence — the strict cmdline fallback
     * in ProcessInspector covers the unverifiable case.
     *
     * The failure is provoked by pre-creating a DIRECTORY at the
     * fingerprint path: `MasterFingerprint::writeTo()` writes its temp
     * file, then fails the final rename onto the directory and throws.
     * The PID file write itself succeeds, so only the fingerprint step
     * fails.
     */
    public function testSaveMasterPidDoesNotThrowWhenFingerprintWriteFails(): void
    {
        $pidFile = $this->tmpDir . '/workerman.pid';
        // Occupy the fingerprint path with a directory so the rename fails.
        mkdir($pidFile . '.fingerprint', 0700);

        $masterPidProp = new \ReflectionProperty(MasterWorker::class, 'masterPid');
        $previousPidFile = Worker::$pidFile;
        $previousLogFile = Worker::$logFile;
        $previousMasterPid = $masterPidProp->getValue();
        $previousOutputStream = Worker::$outputStream;
        // Worker::log() -> safeEcho() requires a writable stream; in a unit
        // test no stream has been initialised, so give it one.
        $testStream = \fopen('php://temp', 'w+');
        if ($testStream === false) {
            self::fail('Unable to open temp stream for test');
        }
        Worker::$outputStream = $testStream;

        try {
            Worker::$pidFile = $pidFile;
            Worker::$logFile = $this->tmpDir . '/workerman.log';
            $masterPidProp->setValue(null, (int) \getmypid());

            $method = new \ReflectionMethod(MasterWorker::class, 'saveMasterPid');
            $method->invoke(null);

            // No exception means the start sequence continues; the PID
            // file must still have been written by the parent.
            self::assertFileExists($pidFile, 'PID file must still be written');
            self::assertSame((string) \getmypid(), (string) file_get_contents($pidFile));
        } finally {
            Worker::$outputStream = $previousOutputStream;
            \fclose($testStream);
            Worker::$pidFile = $previousPidFile;
            Worker::$logFile = $previousLogFile;
            $masterPidProp->setValue(null, $previousMasterPid);
        }
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }

        @rmdir($path);
    }
}
