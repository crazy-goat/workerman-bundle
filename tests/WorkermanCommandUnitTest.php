<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Command\WorkermanCommand;
use CrazyGoat\WorkermanBundle\ConfigLoader;
use CrazyGoat\WorkermanBundle\MasterFingerprint;
use CrazyGoat\WorkermanBundle\ProcessInspector;
use CrazyGoat\WorkermanBundle\ServerManager;
use CrazyGoat\WorkermanBundle\StatusFileReader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Command-level tests that need no live server: a real ServerManager points
 * at a temporary PID file.
 */
final class WorkermanCommandUnitTest extends TestCase
{
    private string $tmpDir;

    private string $pidFile;

    private MockObject&KernelInterface $kernel;

    private ServerManager $manager;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/workerman_command_test_' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        $this->pidFile = $this->tmpDir . '/workerman.pid';

        $configLoader = new ConfigLoader($this->tmpDir, $this->tmpDir . '/cache', false);
        $configLoader->setWorkermanConfig([
            'pid_file' => $this->pidFile,
            'stop_timeout' => 0,
            'status_timeout' => 1,
        ]);

        $this->kernel = $this->createMock(KernelInterface::class);
        $this->manager = new ServerManager(
            $this->kernel,
            $configLoader,
            new ProcessInspector(),
            new StatusFileReader($configLoader),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testStopWhenNotRunningFailsAndKeepsTheGracefulText(): void
    {
        $tester = $this->createTester();

        $tester->execute(['action' => 'stop', '--grace' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('gracefully stopping', $tester->getDisplay());
        self::assertStringContainsString('not running', $tester->getDisplay());
        self::assertStringNotContainsString('stopped successfully', $tester->getDisplay());
    }

    public function testStopWhenNotRunningUsesTheFastText(): void
    {
        $tester = $this->createTester();

        $tester->execute(['action' => 'stop']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Workerman is stopping', $tester->getDisplay());
        self::assertStringNotContainsString('gracefully', $tester->getDisplay());
    }

    public function testReloadWhenNotRunningFails(): void
    {
        $tester = $this->createTester();

        $tester->execute(['action' => 'reload']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not running', $tester->getDisplay());
    }

    public function testStatusWhenNotRunningFails(): void
    {
        $tester = $this->createTester();

        $tester->execute(['action' => 'status']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not running', $tester->getDisplay());
    }

    public function testConnectionsWhenNotRunningFails(): void
    {
        $tester = $this->createTester();

        $tester->execute(['action' => 'connections']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not running', $tester->getDisplay());
    }

    public function testStopTimeoutIsReportedAsFailure(): void
    {
        if (!\extension_loaded('pcntl') || !\extension_loaded('posix')) {
            self::markTestSkipped('pcntl and posix extensions are required.');
        }

        $pid = $this->forkChildIgnoringSignals();
        file_put_contents($this->pidFile, (string) $pid);
        $this->writeMatchingFingerprint($pid);

        try {
            $tester = $this->createTester();
            $tester->execute(['action' => 'stop']);

            self::assertSame(1, $tester->getStatusCode());
            self::assertStringContainsString('stop failed', $tester->getDisplay());
            self::assertStringNotContainsString('stopped successfully', $tester->getDisplay());
        } finally {
            $this->killChild($pid);
        }
    }

    public function testRestartWhenStopTimesOutFails(): void
    {
        if (!\extension_loaded('pcntl') || !\extension_loaded('posix')) {
            self::markTestSkipped('pcntl and posix extensions are required.');
        }

        $pid = $this->forkChildIgnoringSignals();
        file_put_contents($this->pidFile, (string) $pid);
        $this->writeMatchingFingerprint($pid);

        try {
            $tester = $this->createTester();
            $tester->execute(['action' => 'restart', '--grace' => true]);

            self::assertSame(1, $tester->getStatusCode());
            self::assertStringContainsString('gracefully stopping', $tester->getDisplay());
            self::assertStringContainsString('stop failed', $tester->getDisplay());
        } finally {
            $this->killChild($pid);
        }
    }

    public function testGracefulReloadSendsTheSignalAndReportsIt(): void
    {
        if (!\extension_loaded('pcntl') || !\extension_loaded('posix')) {
            self::markTestSkipped('pcntl and posix extensions are required.');
        }

        $pid = $this->forkChildIgnoringSignal(SIGUSR2);
        file_put_contents($this->pidFile, (string) $pid);
        $this->writeMatchingFingerprint($pid);

        try {
            $tester = $this->createTester();
            $tester->execute(['action' => 'reload', '--grace' => true]);

            self::assertSame(0, $tester->getStatusCode());
            self::assertStringContainsString('graceful reload signal sent', $tester->getDisplay());
        } finally {
            $this->killChild($pid);
        }
    }

    public function testStatusAndConnectionsWarnWhenNoDataArrives(): void
    {
        if (!\extension_loaded('pcntl') || !\extension_loaded('posix')) {
            self::markTestSkipped('pcntl and posix extensions are required.');
        }

        $pid = $this->forkChildIgnoring([SIGIOT, SIGIO]);
        file_put_contents($this->pidFile, (string) $pid);
        $this->writeMatchingFingerprint($pid);

        try {
            $tester = $this->createTester();
            $tester->execute(['action' => 'status']);
            self::assertSame(0, $tester->getStatusCode());
            self::assertStringContainsString('No status data available.', $tester->getDisplay());

            $tester = $this->createTester();
            $tester->execute(['action' => 'connections']);
            self::assertSame(0, $tester->getStatusCode());
            self::assertStringContainsString('No connection data available.', $tester->getDisplay());
        } finally {
            $this->killChild($pid);
        }
    }

    private function createTester(): CommandTester
    {
        return new CommandTester(new WorkermanCommand($this->manager));
    }

    private function forkChildIgnoringSignals(): int
    {
        return $this->forkChildIgnoring([SIGINT, SIGQUIT]);
    }

    private function forkChildIgnoringSignal(int $signal): int
    {
        return $this->forkChildIgnoring([$signal]);
    }

    /**
     * @param list<int> $signals
     */
    private function forkChildIgnoring(array $signals): int
    {
        $readyMarker = $this->tmpDir . '/child_ready_' . bin2hex(random_bytes(4));
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            pcntl_async_signals(true);
            foreach ($signals as $signal) {
                pcntl_signal($signal, static function (): void {
                });
            }
            @touch($readyMarker);
            for (;;) {
                usleep(100_000);
            }
        }

        if (!$this->waitForFile($readyMarker)) {
            @posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            self::fail('The forked child did not install its signal handlers');
        }

        return $pid;
    }

    private function writeMatchingFingerprint(int $pid): void
    {
        $fingerprint = new MasterFingerprint(
            pid: $pid,
            startTime: MasterFingerprint::readStartTimeForPid($pid),
            uid: posix_getuid(),
        );
        $fingerprint->writeTo($this->pidFile . '.fingerprint');
    }

    private function waitForFile(string $path): bool
    {
        $deadline = microtime(true) + 3;
        while (!is_file($path) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        return is_file($path);
    }

    private function killChild(int $pid): void
    {
        @posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
