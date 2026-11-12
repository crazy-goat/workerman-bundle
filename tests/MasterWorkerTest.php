<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\MasterFingerprint;
use CrazyGoat\WorkermanBundle\Worker\MasterWorker;
use PHPUnit\Framework\TestCase;
use Workerman\Worker;

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

    public function testResetStdKeepsFd2OnStdoutFile(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !is_dir('/proc/self/fd')) {
            self::markTestSkipped('Daemon mode and /proc/self/fd are Unix/Linux-only.');
        }

        $stdoutFile = $this->tmpDir . '/stdout.log';
        $reportFile = $this->tmpDir . '/fd2.txt';
        $this->runResetStdFixture($stdoutFile, $reportFile);

        $report = json_decode((string) file_get_contents($reportFile), true);
        $this->assertIsArray($report);
        $this->assertSame(realpath($stdoutFile), realpath((string) $report['fd2']));
        $this->assertSame($stdoutFile, $report['error_log'], 'An empty error_log ini must point at stdout_file');
        $content = (string) file_get_contents($stdoutFile);
        self::assertStringContainsString("stderr-line\n", $content);
        self::assertStringContainsString('error-log-line', $content);
    }

    public function testResetStdKeepsFd2OnDevNullWhenStdoutFileCannotBeOpened(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !is_dir('/proc/self/fd')) {
            self::markTestSkipped('Daemon mode and /proc/self/fd are Unix/Linux-only.');
        }

        $reportFile = $this->tmpDir . '/fd2-fallback.txt';
        $this->runResetStdFixture($this->tmpDir, $reportFile);

        $report = json_decode((string) file_get_contents($reportFile), true);
        $this->assertIsArray($report);
        $this->assertSame(realpath('/dev/null'), realpath((string) $report['fd2']));
        $this->assertSame('/dev/null', $report['error_log'], 'The fallback must use the same sink as fd 2');
    }

    private function runResetStdFixture(string $stdoutFile, string $reportFile): void
    {
        $fixture = __DIR__ . '/Fixtures/stderr_redirect_test.php';
        $process = proc_open(
            [PHP_BINARY, '-n', $fixture, $stdoutFile, $reportFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
    }

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

    public function testSaveMasterPidDoesNotThrowWhenFingerprintWriteFails(): void
    {
        $pidFile = $this->tmpDir . '/workerman.pid';
        mkdir($pidFile . '.fingerprint', 0700);

        $masterPidProp = new \ReflectionProperty(MasterWorker::class, 'masterPid');
        $previousPidFile = Worker::$pidFile;
        $previousLogFile = Worker::$logFile;
        $previousMasterPid = $masterPidProp->getValue();
        $previousOutputStream = Worker::$outputStream;
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
