<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\ConfigLoader;
use CrazyGoat\WorkermanBundle\Exception\ServerAlreadyRunningException;
use CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException;
use CrazyGoat\WorkermanBundle\Exception\ServerStopFailedException;
use CrazyGoat\WorkermanBundle\ProcessInspector;
use CrazyGoat\WorkermanBundle\ServerManager;
use CrazyGoat\WorkermanBundle\StatusFileReader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class ServerManagerTest extends TestCase
{
    private string $tmpDir;
    private string $pidFile;
    private string $statusFile;
    private string $connectionsFile;

    private MockObject&KernelInterface $kernel;
    private ConfigLoader $configLoader;
    private ProcessInspector $processInspector;
    private StatusFileReader $statusFileReader;
    private ServerManager $manager;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/workerman_server_test_' . uniqid();
        mkdir($this->tmpDir, 0700, true);

        $this->pidFile = $this->tmpDir . '/workerman.pid';
        $this->statusFile = $this->tmpDir . '/workerman.status';
        $this->connectionsFile = $this->tmpDir . '/workerman.status.connection';

        $this->kernel = $this->createMock(KernelInterface::class);
        $this->configLoader = new ConfigLoader(
            projectDir: sys_get_temp_dir(),
            cacheDir: sys_get_temp_dir(),
            isDebug: false,
        );
        // Use a minimal stop_timeout so timeout-based tests complete quickly.
        // ProcessInspector always adds TIMEOUT_BUFFER (3s), so effective
        // minimum wait is 3 seconds regardless of this value.
        $this->configLoader->setWorkermanConfig([
            'pid_file' => $this->pidFile,
            'stop_timeout' => 0,
            'status_timeout' => 3,
        ]);
        $this->processInspector = new ProcessInspector();
        $this->statusFileReader = new StatusFileReader($this->configLoader);

        $this->manager = new ServerManager(
            $this->kernel,
            $this->configLoader,
            $this->processInspector,
            $this->statusFileReader,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    // ──────────────────────────────────────────────
    // Construction
    // ──────────────────────────────────────────────

    public function testCanBeConstructedWithCollaborators(): void
    {
        $manager = new ServerManager(
            $this->kernel,
            $this->configLoader,
            $this->processInspector,
            $this->statusFileReader,
        );

        $this->assertInstanceOf(ServerManager::class, $manager);
    }

    // ──────────────────────────────────────────────
    // isRunning()
    // ──────────────────────────────────────────────

    public function testIsRunningReturnsFalseWhenNoPidFile(): void
    {
        $this->assertFalse($this->manager->isRunning());
    }

    public function testIsRunningReturnsFalseWhenPidFileEmpty(): void
    {
        file_put_contents($this->pidFile, '');
        $this->assertFalse($this->manager->isRunning());
    }

    public function testIsRunningReturnsFalseWhenPidFilePointsToInvalidPid(): void
    {
        file_put_contents($this->pidFile, '999999999');
        $this->assertFalse($this->manager->isRunning());
    }

    /**
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsRunningReturnsTrueWhenMasterIsRunning(): void
    {
        $pid = $this->forkSleepingChild();
        file_put_contents($this->pidFile, (string) $pid);

        try {
            $this->assertTrue($this->manager->isRunning());
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    /**
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsRunningReturnsFalseAfterMasterDies(): void
    {
        $pid = $this->forkSleepingChild();
        file_put_contents($this->pidFile, (string) $pid);

        // Kill and fully reap the child before checking — zombies are
        // excluded by ProcessInspector::isProcessAlive only for zombie
        // state Z, but posix_kill(pid, 0) still returns true for zombies
        // until the parent reaps them. Blocking wait ensures cleanup.
        $this->killChildBlocking($pid);

        $this->assertFalse($this->manager->isRunning());
    }

    // ──────────────────────────────────────────────
    // stop()
    // ──────────────────────────────────────────────

    public function testStopThrowsServerNotRunningExceptionWhenNoPidFile(): void
    {
        $this->expectException(ServerNotRunningException::class);
        $this->manager->stop();
    }

    /**
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testStopForcefulSendsSIGINT(): void
    {
        $pid = $this->forkSleepingChild();
        file_put_contents($this->pidFile, (string) $pid);

        try {
            $result = $this->manager->stop(false);
            // Reap the zombie left by waitForProcessToStop so isAlive works.
            pcntl_waitpid($pid, $status);

            $this->assertTrue($result, 'stop() should return true when process is stopped');
            $this->assertFalse($this->isAlive($pid), 'Child should be dead after SIGINT');
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    /**
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testStopGracefulSendsSIGQUIT(): void
    {
        $pid = $this->forkSleepingChild();
        file_put_contents($this->pidFile, (string) $pid);

        try {
            $result = $this->manager->stop(true);
            pcntl_waitpid($pid, $status);

            $this->assertTrue($result, 'stop() should return true when process is stopped gracefully');
            $this->assertFalse($this->isAlive($pid), 'Child should be dead after SIGQUIT');
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    /**
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testStopThrowsServerNotRunningExceptionWhenProcessAlreadyDead(): void
    {
        $pid = $this->forkSleepingChild();
        file_put_contents($this->pidFile, (string) $pid);
        $this->killChildBlocking($pid);

        $this->expectException(ServerNotRunningException::class);
        $this->manager->stop();
    }

    public function testStopReturnsFalseWhenProcessDoesNotStop(): void
    {
        $pid = $this->forkChildIgnoringSignals();
        file_put_contents($this->pidFile, (string) $pid);

        // Give the child a moment to install signal handlers
        usleep(200_000);

        $this->assertTrue($this->isAlive($pid), 'Child should be alive before stop()');

        try {
            $result = $this->manager->stop(false);
            $this->assertFalse($result, 'stop() should return false when process ignores SIGINT');
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    // ──────────────────────────────────────────────
    // reload()
    // ──────────────────────────────────────────────

    public function testReloadThrowsServerNotRunningExceptionWhenNoPidFile(): void
    {
        $this->expectException(ServerNotRunningException::class);
        $this->manager->reload();
    }

    /**
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testReloadSendsSIGUSR1(): void
    {
        $signalFile = $this->tmpDir . '/sigusr1_received';
        $pid = $this->forkChildWithAsyncSignalHandler(SIGUSR1, $signalFile);
        file_put_contents($this->pidFile, (string) $pid);

        // Give the child time to install the signal handler
        usleep(200_000);

        $this->assertTrue($this->isAlive($pid), 'Child should be alive before reload()');

        try {
            $this->manager->reload(false);
            $this->waitForFile($signalFile, 3);
            $this->assertFileExists($signalFile, 'SIGUSR1 should have been received by child');
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    /**
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testReloadGracefulSendsSIGUSR2(): void
    {
        $signalFile = $this->tmpDir . '/sigusr2_received';
        $pid = $this->forkChildWithAsyncSignalHandler(SIGUSR2, $signalFile);
        file_put_contents($this->pidFile, (string) $pid);

        usleep(200_000);

        $this->assertTrue($this->isAlive($pid), 'Child should be alive before reload()');

        try {
            $this->manager->reload(true);
            $this->waitForFile($signalFile, 3);
            $this->assertFileExists($signalFile, 'SIGUSR2 should have been received by child');
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    // ──────────────────────────────────────────────
    // getStatus()
    // ──────────────────────────────────────────────

    public function testGetStatusThrowsServerNotRunningExceptionWhenNoPidFile(): void
    {
        $this->expectException(ServerNotRunningException::class);
        $this->manager->getStatus();
    }

    /**
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testGetStatusReturnsParsedContentFromStatusFile(): void
    {
        $pid = $this->forkChildWithAsyncSignalHandler(SIGIOT, $this->statusFile, "ignored header\nworker: running\nmemory: 42MB");
        file_put_contents($this->pidFile, (string) $pid);

        usleep(200_000);

        $this->assertTrue($this->isAlive($pid), 'Child should be alive before getStatus()');

        try {
            $status = $this->manager->getStatus();

            $this->assertNotNull($status, 'getStatus() should return content from status file');
            $this->assertStringContainsString('worker: running', $status);
            $this->assertStringContainsString('memory: 42MB', $status);
            $this->assertStringNotContainsString('ignored header', $status, 'First line of status file should be stripped');
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    /**
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testGetStatusDeletesStatusFileAfterReading(): void
    {
        $pid = $this->forkChildWithAsyncSignalHandler(SIGIOT, $this->statusFile, "header\ndata");
        file_put_contents($this->pidFile, (string) $pid);

        usleep(200_000);

        try {
            $this->manager->getStatus();
            $this->assertFileDoesNotExist($this->statusFile, 'Status file should be deleted after reading');
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    // ──────────────────────────────────────────────
    // getConnections()
    // ──────────────────────────────────────────────

    public function testGetConnectionsThrowsServerNotRunningExceptionWhenNoPidFile(): void
    {
        $this->expectException(ServerNotRunningException::class);
        $this->manager->getConnections();
    }

    /**
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testGetConnectionsReturnsContentFromConnectionsFile(): void
    {
        $expectedContent = "127.0.0.1:54321\n127.0.0.1:54322";
        $pid = $this->forkChildWithAsyncSignalHandler(SIGIO, $this->connectionsFile, $expectedContent);
        file_put_contents($this->pidFile, (string) $pid);

        usleep(200_000);

        $this->assertTrue($this->isAlive($pid), 'Child should be alive before getConnections()');

        try {
            $connections = $this->manager->getConnections();

            $this->assertNotNull($connections, 'getConnections() should return content from connections file');
            $this->assertSame($expectedContent, $connections);
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    /**
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testGetConnectionsDeletesConnectionsFileAfterReading(): void
    {
        $pid = $this->forkChildWithAsyncSignalHandler(SIGIO, $this->connectionsFile, "data");
        file_put_contents($this->pidFile, (string) $pid);

        usleep(200_000);

        try {
            $this->manager->getConnections();
            $this->assertFileDoesNotExist($this->connectionsFile, 'Connections file should be deleted after reading');
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    // ──────────────────────────────────────────────
    // getStatus() / getConnections() — no file created
    // ──────────────────────────────────────────────

    /**
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testGetStatusReturnsNullWhenStatusFileNotCreated(): void
    {
        $pid = $this->forkChildIgnoringSignal(SIGIOT);
        file_put_contents($this->pidFile, (string) $pid);

        usleep(200_000);

        try {
            $status = $this->manager->getStatus();
            $this->assertNull($status, 'getStatus() should return null when no status file appears');
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    /**
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testGetConnectionsReturnsNullWhenFileNotCreated(): void
    {
        $pid = $this->forkChildIgnoringSignal(SIGIO);
        file_put_contents($this->pidFile, (string) $pid);

        usleep(200_000);

        try {
            $connections = $this->manager->getConnections();
            $this->assertNull($connections, 'getConnections() should return null when no connections file appears');
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    // ──────────────────────────────────────────────
    // start() — exception when already running
    // ──────────────────────────────────────────────

    /**
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testStartThrowsServerAlreadyRunningExceptionWhenRunning(): void
    {
        $pid = $this->forkSleepingChild();
        file_put_contents($this->pidFile, (string) $pid);

        usleep(100_000);

        try {
            $this->expectException(ServerAlreadyRunningException::class);
            $this->manager->start();
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    // ──────────────────────────────────────────────
    // restart() — ServerStopFailedException
    // ──────────────────────────────────────────────

    /**
     * Test that restart() throws ServerStopFailedException when stop() fails
     * because the process ignores the stop signal and times out.
     *
     * ProcessInspector's TIMEOUT_BUFFER forces a minimum 3-second wait,
     * so this test is unavoidably slow (~3s).
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testRestartThrowsServerStopFailedExceptionWhenStopFails(): void
    {
        $pid = $this->forkChildIgnoringSignals();
        file_put_contents($this->pidFile, (string) $pid);

        usleep(200_000);

        try {
            $this->expectException(ServerStopFailedException::class);
            $this->manager->restart();
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    // ──────────────────────────────────────────────
    // consumeFile() — atomic read-and-remove (TOCTOU fix)
    // ──────────────────────────────────────────────

    public function testConsumeFileReturnsContentAndRemovesTempFile(): void
    {
        $file = $this->tmpDir . '/test.status';
        file_put_contents($file, 'hello world');

        $reflection = new \ReflectionClass($this->manager);
        $method = $reflection->getMethod('consumeFile');
        $result = $method->invoke($this->manager, $file);

        $this->assertSame('hello world', $result);
        // Original file should no longer exist (renamed + unlinked)
        $this->assertFileDoesNotExist($file);
    }

    public function testConsumeFileReturnsNullWhenFileDoesNotExist(): void
    {
        $file = $this->tmpDir . '/nonexistent.status';

        $reflection = new \ReflectionClass($this->manager);
        $method = $reflection->getMethod('consumeFile');
        $result = $method->invoke($this->manager, $file);

        $this->assertNull($result);
    }

    public function testConsumeFileReturnsNullWhenFileIsEmpty(): void
    {
        $file = $this->tmpDir . '/empty.status';
        file_put_contents($file, '');

        $reflection = new \ReflectionClass($this->manager);
        $method = $reflection->getMethod('consumeFile');
        $result = $method->invoke($this->manager, $file);

        $this->assertNull($result);
    }

    public function testConsumeFileCreatesNoOrphanedTempFiles(): void
    {
        $file = $this->tmpDir . '/cleanup.status';
        file_put_contents($file, 'data');

        $reflection = new \ReflectionClass($this->manager);
        $method = $reflection->getMethod('consumeFile');
        $method->invoke($this->manager, $file);

        // After consumeFile, there should be no .*.tmp files left in the directory
        $remaining = glob($this->tmpDir . '/.cleanup.status.*.tmp');
        $this->assertEmpty($remaining, 'No orphaned temp files should remain after consumeFile');
    }

    /**
     * Verify that a symlink swap at the original path cannot redirect
     * the unlink: after rename(), the original path is gone, and the
     * temp path is the one being unlinked.
     */
    public function testConsumeFileRemovesOriginalInodeAfterRename(): void
    {
        $file = $this->tmpDir . '/inode.status';
        file_put_contents($file, 'inode-data');

        // Stat original inode before consume
        $statBefore = stat($file);
        $this->assertNotFalse($statBefore);

        $reflection = new \ReflectionClass($this->manager);
        $method = $reflection->getMethod('consumeFile');
        $method->invoke($this->manager, $file);

        // Original path should be gone
        clearstatcache(true, $file);
        $this->assertFileDoesNotExist($file);

        // If we create a new file at the same path, it should have a different inode
        file_put_contents($file, 'new-data');
        $statAfter = stat($file);
        $this->assertNotFalse($statAfter);
        $this->assertNotSame(
            $statBefore['ino'],
            $statAfter['ino'],
            'New file at original path should have a different inode',
        );
    }

    /**
     * Negative test: when the status file is a symlink (attacker swap),
     * consumeFile reads the symlink target's content but does NOT delete
     * the target — only the symlink itself (the renamed temp path) is unlinked.
     *
     * This verifies the TOCTOU fix prevents a symlink-swap attack from
     * deleting an arbitrary file.
     */
    public function testConsumeFileDoesNotDeleteSymlinkTarget(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->markTestSkipped('Symlink test requires POSIX');
        }

        // Create a "victim" file
        $victim = $this->tmpDir . '/victim.txt';
        file_put_contents($victim, 'sensitive data');

        // Create a symlink at the status file path pointing to the victim
        $statusFile = $this->tmpDir . '/symlinked.status';
        symlink($victim, $statusFile);

        $this->assertTrue(is_link($statusFile), 'Status file should be a symlink');

        $reflection = new \ReflectionClass($this->manager);
        $method = $reflection->getMethod('consumeFile');
        $result = $method->invoke($this->manager, $statusFile);

        // consumeFile should read through the symlink
        $this->assertSame('sensitive data', $result);

        // The symlink itself should be gone (renamed + unlinked)
        clearstatcache(true, $statusFile);
        $this->assertFileDoesNotExist($statusFile);

        // The victim file must still exist — consumeFile must not have
        // followed the symlink and deleted the target
        $this->assertFileExists($victim, 'Symlink target must not be deleted');
        $this->assertSame('sensitive data', file_get_contents($victim));

        // No orphaned temp files should remain
        $remaining = glob($this->tmpDir . '/.symlinked.status.*.tmp');
        $this->assertEmpty($remaining, 'No orphaned temp files should remain after consumeFile');
    }

    // ──────────────────────────────────────────────
    // Helper — fork a child that sleeps forever
    // ──────────────────────────────────────────────

    private function forkSleepingChild(): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        return $pid;
    }

    /**
     * Fork a child that catches SIGINT and SIGQUIT with an empty handler
     * (prevents default termination). Used for timeout tests.
     */
    private function forkChildIgnoringSignals(): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function (): void {
            });
            pcntl_signal(SIGQUIT, static function (): void {
            });
            for (;;) {
                usleep(100_000);
            }
        }

        return $pid;
    }

    /**
     * Fork a child that catches a signal with an empty handler (prevents
     * default termination but does not create the status/connections file).
     * Used for getStatus/getConnections timeout tests.
     */
    private function forkChildIgnoringSignal(int $signal): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            pcntl_async_signals(true);
            pcntl_signal($signal, static function (): void {
            });
            for (;;) {
                usleep(100_000);
            }
        }

        return $pid;
    }

    /**
     * Fork a child that installs an async signal handler for the given signal.
     * When the signal is received, the handler writes content to $signalFile.
     *
     * Usage for reload tests (signal file as content marker):
     *   $this->forkChildWithAsyncSignalHandler(SIGUSR1, '/tmp/signal_received');
     *
     * Usage for status/connections file tests (signal file is the target):
     *   $this->forkChildWithAsyncSignalHandler(SIGIOT, '/tmp/status.file', "content");
     *
     * @param int        $signal       Signal to handle
     * @param string     $signalFile   Path to write on signal receipt
     * @param string|null $content     Content to write. When null, child creates the
     *                                 file with 'received' as content (marker mode).
     *                                 When non-null, that content is written.
     */
    private function forkChildWithAsyncSignalHandler(int $signal, string $signalFile, ?string $content = null): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            // Enable async signal delivery so handlers fire immediately.
            pcntl_async_signals(true);

            pcntl_signal($signal, static function () use ($signalFile, $content): void {
                file_put_contents($signalFile, $content ?? 'received');
            });

            for (;;) {
                usleep(100_000);
            }
        }

        return $pid;
    }

    // ──────────────────────────────────────────────
    // File / process helpers
    // ──────────────────────────────────────────────

    private function waitForFile(string $path, int $timeout): void
    {
        $deadline = microtime(true) + $timeout;
        while (!file_exists($path) && microtime(true) < $deadline) {
            usleep(50_000);
        }
    }

    private function isAlive(int $pid): bool
    {
        return $pid > 0 && posix_kill($pid, 0);
    }

    /**
     * Kill a child and block until fully reaped (no zombie left behind).
     */
    private function killChildBlocking(int $pid): void
    {
        if ($this->isAlive($pid)) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
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
