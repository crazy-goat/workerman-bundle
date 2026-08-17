<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\CacheWarmupTimeoutConfig;
use CrazyGoat\WorkermanBundle\ConfigLoader;
use CrazyGoat\WorkermanBundle\Exception\ServerAlreadyRunningException;
use CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException;
use CrazyGoat\WorkermanBundle\Exception\ServerStopFailedException;
use CrazyGoat\WorkermanBundle\ProcessInspector;
use CrazyGoat\WorkermanBundle\ServerManager;
use CrazyGoat\WorkermanBundle\StatusFileReader;
use CrazyGoat\WorkermanBundle\Util\Wait;
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

        CacheWarmupTimeoutConfig::reset();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        CacheWarmupTimeoutConfig::reset();
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

    public function testResolveCacheWarmupTimeoutDefaultsWhenHolderEmpty(): void
    {
        $ref = new \ReflectionMethod($this->manager, 'resolveCacheWarmupTimeout');

        $this->assertSame(CacheWarmupTimeoutConfig::DEFAULT, $ref->invoke($this->manager));
    }

    public function testResolveCacheWarmupTimeoutUsesHolderValue(): void
    {
        CacheWarmupTimeoutConfig::set(77);

        $ref = new \ReflectionMethod($this->manager, 'resolveCacheWarmupTimeout');

        $this->assertSame(77, $ref->invoke($this->manager));
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
        $pid = $this->forkMasterLikeChild();
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
        $pid = $this->forkMasterLikeChild();
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
        $pid = $this->forkMasterLikeChild();
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
        $pid = $this->forkMasterLikeChildWithSignalHandler(SIGUSR1, $signalFile);
        file_put_contents($this->pidFile, (string) $pid);

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
        $pid = $this->forkMasterLikeChildWithSignalHandler(SIGUSR2, $signalFile);
        file_put_contents($this->pidFile, (string) $pid);

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
        $pid = $this->forkMasterLikeChildWithSignalHandler(SIGIOT, $this->statusFile, "ignored header\nworker: running\nmemory: 42MB");
        file_put_contents($this->pidFile, (string) $pid);

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
        $pid = $this->forkMasterLikeChildWithSignalHandler(SIGIOT, $this->statusFile, "header\ndata");
        file_put_contents($this->pidFile, (string) $pid);

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
        $pid = $this->forkMasterLikeChildWithSignalHandler(SIGIO, $this->connectionsFile, $expectedContent);
        file_put_contents($this->pidFile, (string) $pid);

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
        $pid = $this->forkMasterLikeChildWithSignalHandler(SIGIO, $this->connectionsFile, "data");
        file_put_contents($this->pidFile, (string) $pid);

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
        $pid = $this->forkMasterLikeChild();
        file_put_contents($this->pidFile, (string) $pid);

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
     *
     * We verify this by checking that:
     * 1. The original path no longer exists after consumeFile
     * 2. A new file at the same path contains our new content (not the
     *    original content, proving the old inode was renamed away)
     */
    public function testConsumeFileRemovesOriginalInodeAfterRename(): void
    {
        $file = $this->tmpDir . '/inode.status';
        file_put_contents($file, 'inode-data');

        $reflection = new \ReflectionClass($this->manager);
        $method = $reflection->getMethod('consumeFile');
        $method->invoke($this->manager, $file);

        // Original path should be gone
        clearstatcache(true, $file);
        $this->assertFileDoesNotExist($file);

        // Create a new file at the same path; it should contain our
        // new data, proving the old inode was renamed (not overwritten)
        file_put_contents($file, 'new-data');
        $this->assertFileExists($file);
        $this->assertSame('new-data', file_get_contents($file));
    }

    // ──────────────────────────────────────────────
    // Master fingerprint integration (issue #327)
    // ──────────────────────────────────────────────

    /**
     * Regression test for issue #327: `isRunning()` must use the
     * fingerprint file when available to verify the master PID.
     *
     * Writes a fingerprint file with a matching PID, then asserts
     * that `isRunning()` returns true.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsRunningUsesFingerprintWhenAvailable(): void
    {
        $pid = $this->forkSleepingChild();
        file_put_contents($this->pidFile, (string) $pid);

        // Write a fingerprint matching the child PID.
        $fingerprintPath = $this->pidFile . '.fingerprint';
        $matchingFingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint(
            pid: $pid,
            startTime: \CrazyGoat\WorkermanBundle\MasterFingerprint::readStartTimeForPid($pid),
            uid: \posix_getuid(),
        );
        $matchingFingerprint->writeTo($fingerprintPath);

        try {
            $this->assertTrue(
                $this->manager->isRunning(),
                'isRunning() must return true when fingerprint matches the PID file PID',
            );
        } finally {
            $this->killChildBlocking($pid);
            @unlink($fingerprintPath);
        }
    }

    /**
     * Regression test for issue #584: `isRunning()` must reject a live
     * plain PHP process when no fingerprint is present, instead of
     * accepting it because its cmdline contains "php".
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsRunningWithoutFingerprintRejectsPlainPhpProcess(): void
    {
        $pid = $this->forkSleepingChild();
        file_put_contents($this->pidFile, (string) $pid);

        // Ensure no fingerprint file exists.
        $fingerprintPath = $this->pidFile . '.fingerprint';
        @unlink($fingerprintPath);

        try {
            $this->assertFalse(
                $this->manager->isRunning(),
                'isRunning() must reject a plain PHP process without a fingerprint',
            );
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    /**
     * Regression test for issue #584: `isRunning()` without a
     * fingerprint falls back to the strict cmdline check and accepts
     * a process carrying the Workerman master title.
     *
     * @requires OS Linux
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsRunningWithoutFingerprintAcceptsMasterTitleProcess(): void
    {
        $pid = $this->forkChildWithMasterTitle();
        file_put_contents($this->pidFile, (string) $pid);

        $fingerprintPath = $this->pidFile . '.fingerprint';
        @unlink($fingerprintPath);

        try {
            $this->assertTrue(
                $this->manager->isRunning(),
                'isRunning() must accept a process carrying the Workerman master title',
            );
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    /**
     * Regression test for issue #327: `isRunning()` must reject a PID
     * whose fingerprint does not match, even if the PID is alive.
     *
     * This is the core security test: an unrelated co-located process
     * whose PID is alive must not be misidentified as the Workerman
     * master.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testIsRunningRejectsUnrelatedProcessWithFingerprint(): void
    {
        $pid = $this->forkSleepingChild();
        file_put_contents($this->pidFile, (string) $pid);

        // Write a fingerprint with a DIFFERENT PID — the child is alive
        // but its PID does not match the fingerprint's PID.
        $fingerprintPath = $this->pidFile . '.fingerprint';
        $mismatchedFingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint(
            pid: $pid + 1_000_000, // PID that does not exist
            startTime: 0,
            uid: \posix_getuid(),
        );
        $mismatchedFingerprint->writeTo($fingerprintPath);

        try {
            $this->assertFalse(
                $this->manager->isRunning(),
                'isRunning() must return false when fingerprint PID does not match the PID file PID',
            );
        } finally {
            $this->killChildBlocking($pid);
            @unlink($fingerprintPath);
        }
    }

    /**
     * Regression test for issue #327: `stop()` must refuse to send
     * SIGINT to a PID whose fingerprint does not match.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testStopRefusesToSignalUnrelatedProcessWithFingerprint(): void
    {
        $pid = $this->forkSleepingChild();
        file_put_contents($this->pidFile, (string) $pid);

        // Write a fingerprint with a DIFFERENT PID — the child is alive
        // but its PID does not match the fingerprint's PID.
        $fingerprintPath = $this->pidFile . '.fingerprint';
        $mismatchedFingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint(
            pid: $pid + 1_000_000, // PID that does not exist
            startTime: 0,
            uid: \posix_getuid(),
        );
        $mismatchedFingerprint->writeTo($fingerprintPath);

        try {
            $thrown = false;
            try {
                $this->manager->stop();
            } catch (\CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException) {
                $thrown = true;
            }

            $this->assertTrue(
                $thrown,
                'stop() must throw ServerNotRunningException when fingerprint PID does not match',
            );
            $this->assertTrue(
                $this->isAlive($pid),
                'Child must still be alive after stop() refused to signal',
            );
        } finally {
            $this->killChildBlocking($pid);
            @unlink($fingerprintPath);
        }
    }

    /**
     * Regression test for issue #327: `reload()` must refuse to send
     * SIGUSR1 to a PID whose fingerprint does not match.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testReloadRefusesToSignalUnrelatedProcessWithFingerprint(): void
    {
        $pid = $this->forkSleepingChild();
        file_put_contents($this->pidFile, (string) $pid);

        $fingerprintPath = $this->pidFile . '.fingerprint';
        $mismatchedFingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint(
            pid: $pid + 1_000_000,
            startTime: 0,
            uid: \posix_getuid(),
        );
        $mismatchedFingerprint->writeTo($fingerprintPath);

        try {
            $thrown = false;
            try {
                $this->manager->reload();
            } catch (\CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException) {
                $thrown = true;
            }

            $this->assertTrue(
                $thrown,
                'reload() must throw ServerNotRunningException when fingerprint PID does not match',
            );
            $this->assertTrue(
                $this->isAlive($pid),
                'Child must still be alive after reload() refused to signal',
            );
        } finally {
            $this->killChildBlocking($pid);
            @unlink($fingerprintPath);
        }
    }

    /**
     * Regression test for issue #584: `stop()` must refuse to signal a
     * plain PHP process (stale/reused PID) when no fingerprint exists.
     *
     * This is the stale-pid scenario: the master died, the PID was
     * reassigned to an unrelated PHP process, and no fingerprint is
     * available (e.g. the fingerprint file was not written). The signal
     * must not be sent.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testStopRefusesToSignalPlainPhpProcessWithoutFingerprint(): void
    {
        $pid = $this->forkSleepingChild();
        file_put_contents($this->pidFile, (string) $pid);

        $fingerprintPath = $this->pidFile . '.fingerprint';
        @unlink($fingerprintPath);

        try {
            $thrown = false;
            try {
                $this->manager->stop();
            } catch (\CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException) {
                $thrown = true;
            }

            $this->assertTrue(
                $thrown,
                'stop() must throw ServerNotRunningException for a plain PHP process without a fingerprint',
            );
            $this->assertTrue(
                $this->isAlive($pid),
                'Plain PHP child must still be alive after stop() refused to signal',
            );
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    /**
     * Regression test for issue #584: `reload()` must refuse to signal a
     * plain PHP process (stale/reused PID) when no fingerprint exists.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testReloadRefusesToSignalPlainPhpProcessWithoutFingerprint(): void
    {
        $pid = $this->forkSleepingChild();
        file_put_contents($this->pidFile, (string) $pid);

        $fingerprintPath = $this->pidFile . '.fingerprint';
        @unlink($fingerprintPath);

        try {
            $thrown = false;
            try {
                $this->manager->reload();
            } catch (\CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException) {
                $thrown = true;
            }

            $this->assertTrue(
                $thrown,
                'reload() must throw ServerNotRunningException for a plain PHP process without a fingerprint',
            );
            $this->assertTrue(
                $this->isAlive($pid),
                'Plain PHP child must still be alive after reload() refused to signal',
            );
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    /**
     * Regression test for issue #584: `getStatus()` must refuse to signal
     * a plain PHP process (stale/reused PID) when no fingerprint exists.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testGetStatusRefusesToSignalPlainPhpProcessWithoutFingerprint(): void
    {
        $pid = $this->forkSleepingChild();
        file_put_contents($this->pidFile, (string) $pid);

        $fingerprintPath = $this->pidFile . '.fingerprint';
        @unlink($fingerprintPath);

        try {
            $thrown = false;
            try {
                $this->manager->getStatus();
            } catch (\CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException) {
                $thrown = true;
            }

            $this->assertTrue(
                $thrown,
                'getStatus() must throw ServerNotRunningException for a plain PHP process without a fingerprint',
            );
            $this->assertTrue(
                $this->isAlive($pid),
                'Plain PHP child must still be alive after getStatus() refused to signal',
            );
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    /**
     * Regression test for issue #584: `getConnections()` must refuse to
     * signal a plain PHP process (stale/reused PID) when no fingerprint
     * exists.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testGetConnectionsRefusesToSignalPlainPhpProcessWithoutFingerprint(): void
    {
        $pid = $this->forkSleepingChild();
        file_put_contents($this->pidFile, (string) $pid);

        $fingerprintPath = $this->pidFile . '.fingerprint';
        @unlink($fingerprintPath);

        try {
            $thrown = false;
            try {
                $this->manager->getConnections();
            } catch (\CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException) {
                $thrown = true;
            }

            $this->assertTrue(
                $thrown,
                'getConnections() must throw ServerNotRunningException for a plain PHP process without a fingerprint',
            );
            $this->assertTrue(
                $this->isAlive($pid),
                'Plain PHP child must still be alive after getConnections() refused to signal',
            );
        } finally {
            $this->killChildBlocking($pid);
        }
    }

    /**
     * Regression test for issue #327: `stop()` must remove the
     * fingerprint sidecar file after a successful stop.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testStopRemovesFingerprintFileAfterSuccessfulStop(): void
    {
        $pid = $this->forkSleepingChild();
        file_put_contents($this->pidFile, (string) $pid);

        $fingerprintPath = $this->pidFile . '.fingerprint';
        $matchingFingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint(
            pid: $pid,
            startTime: \CrazyGoat\WorkermanBundle\MasterFingerprint::readStartTimeForPid($pid),
            uid: \posix_getuid(),
        );
        $matchingFingerprint->writeTo($fingerprintPath);

        $this->assertFileExists($fingerprintPath, 'Fingerprint file should exist before stop()');

        try {
            $result = $this->manager->stop(false);
            pcntl_waitpid($pid, $status);

            $this->assertTrue($result, 'stop() should return true when process is stopped');
            $this->assertFileDoesNotExist(
                $fingerprintPath,
                'Fingerprint file should be removed after successful stop()',
            );
        } finally {
            $this->killChildBlocking($pid);
            @unlink($fingerprintPath);
        }
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
     * Fork a child that looks like a Workerman master: it carries the
     * master process title and a matching fingerprint is written by the
     * parent. Used by signal-path tests so verification passes on every
     * platform (fingerprint) and the strict cmdline fallback also passes
     * on Linux (title, simulated via execve argv[0] — see
     * {@see forkChildWithMasterTitle}).
     */
    private function forkMasterLikeChild(): int
    {
        $marker = \sys_get_temp_dir() . '/workerman_master_title_' . \bin2hex(\random_bytes(4));
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            $this->execAsMasterTitle($marker);
        }

        $this->waitForFile($marker, 3);
        @\unlink($marker);
        $this->writeMatchingFingerprint($pid);

        return $pid;
    }

    /**
     * Fork a child that looks like a Workerman master AND installs an
     * async signal handler for the given signal (same behaviors as
     * {@see forkChildWithAsyncSignalHandler}). The master identity is
     * established by the matching fingerprint only — the process title
     * is irrelevant here and is intentionally not simulated (it cannot
     * survive the exec that a signal handler requires).
     */
    private function forkMasterLikeChildWithSignalHandler(int $signal, string $signalFile, ?string $content = null): int
    {
        $readyMarker = $this->tmpDir . '/child_ready_' . bin2hex(random_bytes(4));
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

            // Signal the parent that handlers are installed.
            @touch($readyMarker);

            for (;;) {
                // Keep-alive loop — NOT a wait. The usleep paces signal
                // delivery; it must not be replaced with Wait::until().
                usleep(100_000);
            }
        }

        $this->writeMatchingFingerprint($pid);
        $this->waitForChildReadyOrKill($readyMarker, $pid);

        return $pid;
    }

    /**
     * Fork a child that carries the Workerman master title but gets NO
     * fingerprint — used by strict fallback tests (Linux cmdline check).
     *
     * The title is simulated with `execve()` (argv[0]) rather than
     * `cli_set_process_title()`, whose effect on /proc/$pid/cmdline
     * varies by PHP build (PHP >= 8.5 keeps the original argv on some
     * builds) — execve is kernel behaviour, identical everywhere.
     */
    private function forkChildWithMasterTitle(): int
    {
        $marker = \sys_get_temp_dir() . '/workerman_master_title_' . \bin2hex(\random_bytes(4));
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            $this->execAsMasterTitle($marker);
        }

        $this->waitForFile($marker, 3);
        @\unlink($marker);

        return $pid;
    }

    /**
     * Write a fingerprint file matching the given PID (current UID,
     * real start time) next to the test PID file.
     */
    private function writeMatchingFingerprint(int $pid): void
    {
        $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint(
            pid: $pid,
            startTime: \CrazyGoat\WorkermanBundle\MasterFingerprint::readStartTimeForPid($pid),
            uid: \posix_getuid(),
        );
        $fingerprint->writeTo($this->pidFile . '.fingerprint');
    }

    /**
     * Replace the current process with one that carries the Workerman
     * master process title in its command line (used by the fork
     * helpers). The title is simulated with `bash -c 'exec -a ...'`
     * (execve argv[0]) rather than `cli_set_process_title()`, whose
     * effect on /proc/$pid/cmdline varies by PHP build (PHP >= 8.5
     * keeps the original argv on some builds). Execve is kernel
     * behaviour, identical everywhere. The child touches $marker AFTER
     * the exec so the parent can synchronise on it.
     *
     * The exec'd process redirects stdio to `/dev/null` so it cannot
     * keep PHPUnit's output descriptors open, and it uses a tiny PHP
     * loop (not `/bin/sh`) because the shell loop ignores SIGINT/SIGQUIT
     * on macOS and makes stop() tests hang in `waitpid()`.
     */
    private function execAsMasterTitle(string $marker): never
    {
        $title = 'WorkerMan: master process  start_file=' . __FILE__;
        $script = <<<'PHP'
pcntl_async_signals(true);
pcntl_signal(SIGINT, static function (): void { exit(0); });
pcntl_signal(SIGQUIT, static function (): void { exit(0); });
pcntl_signal(SIGTERM, static function (): void { exit(0); });
touch(__MARKER__);
while (true) {
    sleep(1);
}
PHP;
        $script = str_replace('__MARKER__', var_export($marker, true), $script);
        $command = 'exec -a ' . escapeshellarg($title)
            . ' ' . escapeshellarg(\PHP_BINARY)
            . ' -r ' . escapeshellarg($script)
            . ' < /dev/null > /dev/null 2>&1';

        pcntl_exec('/bin/bash', ['-c', $command]);
        \fwrite(\STDERR, 'Unable to exec master-title process' . \PHP_EOL);
        exit(1);
    }

    /**
     * Fork a child that catches SIGINT and SIGQUIT with an empty handler
     * (prevents default termination). Used for timeout tests. A matching
     * fingerprint is written so verification of the master identity
     * passes on every platform (issue #584 fail-closed behaviour).
     */
    private function forkChildIgnoringSignals(): int
    {
        $readyMarker = $this->tmpDir . '/child_ready_' . bin2hex(random_bytes(4));
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
            // Signal the parent that handlers are installed.
            @touch($readyMarker);
            for (;;) {
                // Keep-alive loop — NOT a wait.
                usleep(100_000);
            }
        }

        $this->writeMatchingFingerprint($pid);
        $this->waitForChildReadyOrKill($readyMarker, $pid);

        return $pid;
    }

    /**
     * Fork a child that catches a signal with an empty handler (prevents
     * default termination but does not create the status/connections file).
     * Used for getStatus/getConnections timeout tests. A matching
     * fingerprint is written so verification passes on every platform
     * (issue #584 fail-closed behaviour).
     */
    private function forkChildIgnoringSignal(int $signal): int
    {
        $readyMarker = $this->tmpDir . '/child_ready_' . bin2hex(random_bytes(4));
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            pcntl_async_signals(true);
            pcntl_signal($signal, static function (): void {
            });
            // Signal the parent that the handler is installed.
            @touch($readyMarker);
            for (;;) {
                // Keep-alive loop — NOT a wait.
                usleep(100_000);
            }
        }

        $this->writeMatchingFingerprint($pid);
        $this->waitForChildReadyOrKill($readyMarker, $pid);

        return $pid;
    }

    // ──────────────────────────────────────────────
    // File / process helpers
    // ──────────────────────────────────────────────

    private function waitForFile(string $path, int $timeout): void
    {
        Wait::until(static fn(): bool => file_exists($path), $timeout);
    }

    /**
     * Wait until a forked child has installed its signal handlers by
     * polling for a readiness marker file, replacing the fixed
     * `usleep(200_000)` that guessed at handler-install latency.
     */
    private function waitForChildReady(string $marker): void
    {
        $ready = Wait::until(static fn(): bool => file_exists($marker), 5);
        @unlink($marker);
        $this->assertTrue($ready, 'Child did not install signal handlers within 5s');
    }

    /**
     * Wait for a child readiness marker, killing the child when the wait
     * fails: waitForChildReady's AssertionFailedError would propagate
     * out of the fork helper before the test method's try/finally is
     * entered, orphaning the child.
     */
    private function waitForChildReadyOrKill(string $marker, int $pid): void
    {
        try {
            $this->waitForChildReady($marker);
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->killChildBlocking($pid);
            throw $e;
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
