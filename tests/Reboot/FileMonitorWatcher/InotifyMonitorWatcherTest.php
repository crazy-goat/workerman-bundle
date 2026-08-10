<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Reboot\FileMonitorWatcher;

use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\FileMonitorWatcher;
use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\InotifyMonitorWatcher;
use PHPUnit\Framework\TestCase;
use Workerman\Events\EventInterface;
use Workerman\Worker;

/** @psalm-suppress PropertyNotSetInConstructor */
final class InotifyMonitorWatcherTest extends TestCase
{
    private const IN_CREATE = 256;
    private const IN_MODIFY = 2;
    private const IN_DELETE = 512;
    private const IN_IGNORED = 32768;
    private const IN_ISDIR = 1073741824;

    private ?EventInterface $originalEvent = null;
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->originalEvent = Worker::$globalEvent;
    }

    protected function tearDown(): void
    {
        Worker::$globalEvent = $this->originalEvent;

        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    // ---- Tests that do NOT require inotify extension ----

    public function testIsFlagSetExactMatch(): void
    {
        $watcher = $this->createWatcherInstance();

        $this->assertTrue(
            $this->invokeIsFlagSet($watcher, self::IN_CREATE, self::IN_CREATE),
            'isFlagSet should return true when mask exactly matches the flag',
        );
    }

    public function testIsFlagSetMultipleFlagsAllSet(): void
    {
        $watcher = $this->createWatcherInstance();

        $this->assertTrue(
            $this->invokeIsFlagSet($watcher, self::IN_CREATE | self::IN_MODIFY, self::IN_CREATE | self::IN_MODIFY),
            'isFlagSet should return true when all bits in flag are set in mask',
        );
    }

    public function testIsFlagSetSubsetOfBits(): void
    {
        $watcher = $this->createWatcherInstance();

        $this->assertTrue(
            $this->invokeIsFlagSet($watcher, self::IN_CREATE | self::IN_MODIFY | self::IN_DELETE, self::IN_MODIFY),
            'isFlagSet should return true when mask has the flag bit set (subset check)',
        );
    }

    public function testIsFlagSetFlagNotInMask(): void
    {
        $watcher = $this->createWatcherInstance();

        $this->assertFalse(
            $this->invokeIsFlagSet($watcher, self::IN_CREATE | self::IN_MODIFY, self::IN_DELETE),
            'isFlagSet should return false when mask does not have the flag bit set',
        );
    }

    public function testIsFlagSetZeroMask(): void
    {
        $watcher = $this->createWatcherInstance();

        $this->assertFalse(
            $this->invokeIsFlagSet($watcher, 0, self::IN_CREATE),
            'isFlagSet should return false when mask is zero',
        );
    }

    public function testIsFlagSetInIgnoredVsInCreateIsDir(): void
    {
        $watcher = $this->createWatcherInstance();

        $this->assertFalse(
            $this->invokeIsFlagSet($watcher, self::IN_IGNORED, self::IN_CREATE | self::IN_ISDIR),
            'IN_IGNORED should not match a IN_CREATE|IN_ISDIR check',
        );
    }

    public function testStartIsNoOpWhenInotifyNotAvailable(): void
    {
        if (function_exists('inotify_init')) {
            $this->markTestSkipped('Inotify is available on this system; this test checks the no-extension path');
        }

        $worker = $this->createMock(Worker::class);
        $watcher = $this->createWatcherWithSourceDir($worker, sys_get_temp_dir());

        Worker::$globalEvent = null;
        $watcher->start();

        $pathByWd = $this->getPrivateProperty($watcher, 'pathByWd');
        $this->assertCount(0, $pathByWd, 'pathByWd should be empty when inotify is not available');
    }

    // ---- Tests that require inotify extension (skipped on macOS) ----

    /**
     * @requires extension inotify
     */
    public function testStartInitializesInotifyAndRegistersHandler(): void
    {
        $tmpDir = $this->createTempDir();

        $eventLoop = $this->createMock(EventInterface::class);
        $eventLoop->expects($this->once())
            ->method('onReadable')
            ->with($this->isType('resource'), $this->isType('callable'));
        $eventLoop->expects($this->once())
            ->method('delay')
            ->with($this->isType('float'), $this->isType('callable'))
            ->willReturn(1);

        Worker::$globalEvent = $eventLoop;

        $worker = $this->createMock(Worker::class);
        $watcher = $this->createWatcherWithSourceDir($worker, $tmpDir);

        $watcher->start();

        $fd = $this->getPrivateProperty($watcher, 'fd');
        $this->assertIsResource($fd, 'start() should create an inotify file descriptor');
    }

    /**
     * @requires extension inotify
     */
    public function testStartWatchesOnlyTopLevelDirectories(): void
    {
        $tmpDir = $this->createTempDir();
        mkdir($tmpDir . '/sub1', 0700);
        mkdir($tmpDir . '/sub1/sub2', 0700);
        mkdir($tmpDir . '/other', 0700);

        $worker = $this->createMock(Worker::class);
        $watcher = $this->createWatcherWithSourceDir($worker, $tmpDir, ['*.php']);
        $this->setUpEventLoop();

        $watcher->start();

        $pathByWd = $this->getPrivateProperty($watcher, 'pathByWd');

        // After start(), only the root directory should be watched.
        // Subdirectories are watched lazily via deferredWalk().
        $this->assertCount(1, $pathByWd, 'pathByWd should contain only the root directory after start()');
        $this->assertContains($tmpDir, $pathByWd, 'pathByWd should contain the root source directory');
    }

    /**
     * @requires extension inotify
     */
    public function testDeferredWalkWatchesAllSubdirectories(): void
    {
        $tmpDir = $this->createTempDir();
        mkdir($tmpDir . '/sub1', 0700);
        mkdir($tmpDir . '/sub1/sub2', 0700);
        mkdir($tmpDir . '/other', 0700);

        $worker = $this->createMock(Worker::class);
        $watcher = $this->createWatcherWithSourceDir($worker, $tmpDir, ['*.php']);
        $this->setUpEventLoop();

        $watcher->start();

        // Invoke the deferred walk explicitly
        $this->invokeDeferredWalk($watcher);

        $pathByWd = $this->getPrivateProperty($watcher, 'pathByWd');

        $this->assertCount(4, $pathByWd, 'pathByWd should contain entries for tmpDir, sub1, sub2, and other');
        $this->assertContains($tmpDir, $pathByWd, 'pathByWd should contain the root source directory');
        $this->assertContains($tmpDir . '/sub1', $pathByWd, 'pathByWd should contain sub1 directory');
        $this->assertContains($tmpDir . '/sub1/sub2', $pathByWd, 'pathByWd should contain sub1/sub2 directory');
        $this->assertContains($tmpDir . '/other', $pathByWd, 'pathByWd should contain other directory');
    }

    /**
     * @requires extension inotify
     */
    public function testOnNotifyTriggersReloadOnCreateEvent(): void
    {
        $tmpDir = $this->createTempDir();

        $worker = $this->createMock(Worker::class);
        $watcher = $this->createWatcherWithSourceDir($worker, $tmpDir, ['*.php']);
        $this->setUpEventLoop();

        $watcher->start();

        file_put_contents($tmpDir . '/newfile.php', '<?php');
        $this->waitForInotifyEvents();

        $fd = $this->getPrivateProperty($watcher, 'fd');
        $this->invokeOnNotify($watcher, $fd);

        $this->assertNotNull(
            $this->getPrivateProperty($watcher, 'reloadCallback'),
            'reloadCallback should be set after processing a matching CREATE event',
        );
    }

    /**
     * @requires extension inotify
     */
    public function testOnNotifyTriggersReloadOnModifyEvent(): void
    {
        $tmpDir = $this->createTempDir();

        file_put_contents($tmpDir . '/existing.php', '<?php // v1');

        $worker = $this->createMock(Worker::class);
        $watcher = $this->createWatcherWithSourceDir($worker, $tmpDir, ['*.php']);
        $this->setUpEventLoop();

        $watcher->start();

        file_put_contents($tmpDir . '/existing.php', '<?php // v2');
        $this->waitForInotifyEvents();

        $fd = $this->getPrivateProperty($watcher, 'fd');
        $this->invokeOnNotify($watcher, $fd);

        $this->assertNotNull(
            $this->getPrivateProperty($watcher, 'reloadCallback'),
            'reloadCallback should be set after processing a matching MODIFY event',
        );
    }

    /**
     * @requires extension inotify
     */
    public function testOnNotifyDoesNotTriggerReloadForNonMatchingFile(): void
    {
        $tmpDir = $this->createTempDir();

        $worker = $this->createMock(Worker::class);
        $watcher = $this->createWatcherWithSourceDir($worker, $tmpDir, ['*.php']);
        $this->setUpEventLoop();

        $watcher->start();

        file_put_contents($tmpDir . '/data.csv', 'a,b,c');
        $this->waitForInotifyEvents();

        $fd = $this->getPrivateProperty($watcher, 'fd');
        $this->invokeOnNotify($watcher, $fd);

        $this->assertNull(
            $this->getPrivateProperty($watcher, 'reloadCallback'),
            'reloadCallback should remain null for non-matching file events',
        );
    }

    /**
     * @requires extension inotify
     */
    public function testOnNotifyIgnoresInIgnoredAndRemovesPath(): void
    {
        $tmpDir = $this->createTempDir();
        mkdir($tmpDir . '/subdir', 0700);

        $worker = $this->createMock(Worker::class);
        $watcher = $this->createWatcherWithSourceDir($worker, $tmpDir, ['*.php']);
        $this->setUpEventLoop();

        $watcher->start();

        // Invoke the deferred walk so that subdir is watched
        $this->invokeDeferredWalk($watcher);

        $pathByWdBefore = $this->getPrivateProperty($watcher, 'pathByWd');
        $this->assertNotEmpty($pathByWdBefore, 'pathByWd should have entries before removal');

        rmdir($tmpDir . '/subdir');
        clearstatcache();
        $this->waitForInotifyEvents();

        $fd = $this->getPrivateProperty($watcher, 'fd');
        $this->invokeOnNotify($watcher, $fd);

        $pathByWdAfterFirst = $this->getPrivateProperty($watcher, 'pathByWd');
        $this->assertCount(
            count($pathByWdBefore) - 1,
            $pathByWdAfterFirst,
            'pathByWd should have one fewer entry after IN_IGNORED for subdir',
        );

        rmdir($tmpDir);
        clearstatcache();
        $this->waitForInotifyEvents();

        $this->invokeOnNotify($watcher, $fd);

        $pathByWdAfterSecond = $this->getPrivateProperty($watcher, 'pathByWd');
        $this->assertCount(
            count($pathByWdAfterFirst) - 1,
            $pathByWdAfterSecond,
            'pathByWd should have one fewer entry after IN_IGNORED for root dir',
        );
    }

    /**
     * @requires extension inotify
     */
    public function testOnNotifyWatchesNewlyCreatedSubdirectory(): void
    {
        $tmpDir = $this->createTempDir();

        $worker = $this->createMock(Worker::class);
        $watcher = $this->createWatcherWithSourceDir($worker, $tmpDir, ['*.php']);
        $this->setUpEventLoop();

        $watcher->start();

        $pathByWdBefore = $this->getPrivateProperty($watcher, 'pathByWd');
        $dirCountBefore = count($pathByWdBefore);

        mkdir($tmpDir . '/newsub');
        $this->waitForInotifyEvents();

        $fd = $this->getPrivateProperty($watcher, 'fd');
        $this->invokeOnNotify($watcher, $fd);

        $pathByWdAfter = $this->getPrivateProperty($watcher, 'pathByWd');
        $this->assertCount(
            $dirCountBefore + 1,
            $pathByWdAfter,
            'pathByWd should have one more entry after a new subdirectory is created',
        );
        $this->assertContains($tmpDir . '/newsub', $pathByWdAfter, 'newsub should be in pathByWd after creation');
    }

    /**
     * @requires extension inotify
     */
    public function testOnNotifySkipsAlreadyScheduledReload(): void
    {
        $tmpDir = $this->createTempDir();

        $delayCount = 0;
        $eventLoop = $this->createMock(EventInterface::class);
        $eventLoop->method('onReadable')->willReturnCallback(function (): void {
        });
        $eventLoop->method('delay')->willReturnCallback(function () use (&$delayCount): int {
            ++$delayCount;

            return $delayCount;
        });

        Worker::$globalEvent = $eventLoop;

        $worker = $this->createMock(Worker::class);
        $watcher = $this->createWatcherWithSourceDir($worker, $tmpDir, ['*.php']);

        $watcher->start();

        file_put_contents($tmpDir . '/a.php', '<?php');
        file_put_contents($tmpDir . '/b.php', '<?php');
        $this->waitForInotifyEvents();

        $fd = $this->getPrivateProperty($watcher, 'fd');

        $this->invokeOnNotify($watcher, $fd);
        $this->assertSame(2, $delayCount, 'First onNotify should schedule one reload (total: 1 from start + 1 from onNotify)');

        $this->invokeOnNotify($watcher, $fd);
        $this->assertSame(2, $delayCount, 'Second onNotify should NOT schedule another reload while callback is set');
    }

    /**
     * @requires extension inotify
     */
    public function testPathByWdPopulatedAfterStart(): void
    {
        $tmpDir = $this->createTempDir();
        mkdir($tmpDir . '/alpha', 0700);
        mkdir($tmpDir . '/beta', 0700);

        $worker = $this->createMock(Worker::class);
        $watcher = $this->createWatcherWithSourceDir($worker, $tmpDir, ['*.php']);
        $this->setUpEventLoop();

        $watcher->start();

        // Invoke the deferred walk to populate all subdirectories
        $this->invokeDeferredWalk($watcher);

        $pathByWd = $this->getPrivateProperty($watcher, 'pathByWd');

        $this->assertContains($tmpDir, $pathByWd, 'Root directory should be in pathByWd');
        $this->assertContains($tmpDir . '/alpha', $pathByWd, 'alpha subdirectory should be in pathByWd');
        $this->assertContains($tmpDir . '/beta', $pathByWd, 'beta subdirectory should be in pathByWd');

        foreach ($pathByWd as $wd => $path) {
            $this->assertIsInt($wd, 'Watch descriptor should be an integer');
            $this->assertDirectoryExists($path, 'Each path in pathByWd should be an existing directory');
        }
    }

    /**
     * @requires extension inotify
     */
    public function testInIgnoredCleanupRunsWhileReloadIsPending(): void
    {
        $tmpDir = $this->createTempDir();
        mkdir($tmpDir . '/subdir', 0700);

        $delayCount = 0;
        $eventLoop = $this->createMock(EventInterface::class);
        $eventLoop->method('onReadable')->willReturnCallback(function (): void {
        });
        $eventLoop->method('delay')->willReturnCallback(function () use (&$delayCount): int {
            ++$delayCount;

            return $delayCount;
        });

        Worker::$globalEvent = $eventLoop;

        $worker = $this->createMock(Worker::class);
        $watcher = $this->createWatcherWithSourceDir($worker, $tmpDir, ['*.php']);

        $watcher->start();
        $this->invokeDeferredWalk($watcher);

        // Arm a pending reload with a matching file change.
        file_put_contents($tmpDir . '/a.php', '<?php');
        $this->waitForInotifyEvents();

        $fd = $this->getPrivateProperty($watcher, 'fd');
        $this->invokeOnNotify($watcher, $fd);

        $this->assertNotNull(
            $this->getPrivateProperty($watcher, 'reloadCallback'),
            'precondition: a reload must be pending before the subdirectory is deleted',
        );
        $delaysBefore = $delayCount;

        // Delete a watched subdirectory while the reload is still pending.
        rmdir($tmpDir . '/subdir');
        clearstatcache();
        $this->waitForInotifyEvents();
        $this->invokeOnNotify($watcher, $fd);

        $pathByWd = $this->getPrivateProperty($watcher, 'pathByWd');
        $watchedPaths = $this->getPrivateProperty($watcher, 'watchedPaths');
        $this->assertNotContains(
            $tmpDir . '/subdir',
            $pathByWd,
            'IN_IGNORED cleanup must run while a reload is pending',
        );
        $this->assertArrayNotHasKey(
            $tmpDir . '/subdir',
            $watchedPaths,
            'watchedPaths must be cleaned while a reload is pending',
        );
        $this->assertNotNull(
            $this->getPrivateProperty($watcher, 'reloadCallback'),
            'the pending reload must stay armed',
        );
        $this->assertSame($delaysBefore, $delayCount, 'no additional reload may be scheduled');
        $this->assertMapsConsistent($watcher);
    }

    /**
     * @requires extension inotify
     */
    public function testDeletedDirectoryIsWatchedAgainAfterRecreation(): void
    {
        $tmpDir = $this->createTempDir();
        mkdir($tmpDir . '/subdir', 0700);

        $worker = $this->createMock(Worker::class);
        $watcher = $this->createWatcherWithSourceDir($worker, $tmpDir, ['*.php']);
        $this->setUpEventLoop();

        $watcher->start();
        $this->invokeDeferredWalk($watcher);

        rmdir($tmpDir . '/subdir');
        clearstatcache();
        $this->waitForInotifyEvents();

        $fd = $this->getPrivateProperty($watcher, 'fd');
        $this->invokeOnNotify($watcher, $fd);

        $this->assertNotContains(
            $tmpDir . '/subdir',
            $this->getPrivateProperty($watcher, 'pathByWd'),
            'deleted directory must be cleaned from pathByWd',
        );
        $this->assertArrayNotHasKey(
            $tmpDir . '/subdir',
            $this->getPrivateProperty($watcher, 'watchedPaths'),
            'deleted directory must be cleaned from watchedPaths',
        );

        mkdir($tmpDir . '/subdir', 0700);
        $this->waitForInotifyEvents();
        $this->invokeOnNotify($watcher, $fd);

        $this->assertContains(
            $tmpDir . '/subdir',
            $this->getPrivateProperty($watcher, 'pathByWd'),
            'recreated directory must be watched again',
        );
        $this->assertArrayHasKey(
            $tmpDir . '/subdir',
            $this->getPrivateProperty($watcher, 'watchedPaths'),
            'recreated directory must be recorded as watched',
        );
        $this->assertMapsConsistent($watcher);
    }

    /**
     * @requires extension inotify
     */
    public function testEventWithUnknownWatchDescriptorIsSkipped(): void
    {
        $tmpDir = $this->createTempDir();

        // Created BEFORE the watcher starts, so the root watch never queues a
        // known-wd IN_CREATE|IN_ISDIR for it: the only events referencing it
        // are the ones from the unrecorded watch below.
        $unrecordedDir = $tmpDir . '/unrecorded';
        mkdir($unrecordedDir, 0700);

        $worker = $this->createMock(Worker::class);
        $watcher = $this->createWatcherWithSourceDir($worker, $tmpDir, ['*.php']);
        $this->setUpEventLoop();

        $watcher->start();

        $fd = $this->getPrivateProperty($watcher, 'fd');
        \assert(\is_resource($fd));

        // Register a watch directly on the watcher's descriptor, bypassing the
        // bookkeeping maps: its events then carry a descriptor onNotify() has
        // never seen.
        \inotify_add_watch($fd, $unrecordedDir, self::IN_CREATE | self::IN_ISDIR);

        $pathByWdBefore = $this->getPrivateProperty($watcher, 'pathByWd');
        $watchedPathsBefore = $this->getPrivateProperty($watcher, 'watchedPaths');

        mkdir($unrecordedDir . '/child', 0700);
        $this->waitForInotifyEvents();
        $this->invokeOnNotify($watcher, $fd);

        $this->assertSame(
            $pathByWdBefore,
            $this->getPrivateProperty($watcher, 'pathByWd'),
            'an event for an unknown watch descriptor must not register a watch',
        );
        $this->assertSame(
            $watchedPathsBefore,
            $this->getPrivateProperty($watcher, 'watchedPaths'),
            'an event for an unknown watch descriptor must not touch watchedPaths',
        );
    }

    /**
     * @requires extension inotify
     */
    public function testMovedInDirectoryWithPreExistingChildrenIsWatched(): void
    {
        $tmpDir = $this->createTempDir();

        $staging = sys_get_temp_dir() . '/inotify_staging_' . bin2hex(random_bytes(4));
        mkdir($staging . '/nested', 0700, true);
        file_put_contents($staging . '/nested/cache.php', '<?php');

        $worker = $this->createMock(Worker::class);
        $watcher = $this->createWatcherWithSourceDir($worker, $tmpDir, ['*.php']);
        $this->setUpEventLoop();

        $watcher->start();

        // A directory moved into the tree arrives as IN_MOVED_TO|IN_ISDIR with
        // its children already present (they were created outside any watch).
        rename($staging, $tmpDir . '/moved');
        $this->waitForInotifyEvents();

        $fd = $this->getPrivateProperty($watcher, 'fd');
        $this->invokeOnNotify($watcher, $fd);

        $pathByWd = $this->getPrivateProperty($watcher, 'pathByWd');
        $watchedPaths = $this->getPrivateProperty($watcher, 'watchedPaths');
        $this->assertContains($tmpDir . '/moved', $pathByWd, 'moved-in directory must be watched');
        $this->assertContains(
            $tmpDir . '/moved/nested',
            $pathByWd,
            'pre-existing child of a moved-in directory must be watched',
        );
        $this->assertArrayHasKey($tmpDir . '/moved', $watchedPaths);
        $this->assertArrayHasKey($tmpDir . '/moved/nested', $watchedPaths);
        $this->assertMapsConsistent($watcher);
    }

    /**
     * @requires extension inotify
     */
    public function testMovingWatchedDirectoryKeepsMapsConsistent(): void
    {
        $tmpDir = $this->createTempDir();
        mkdir($tmpDir . '/alpha', 0700);

        $worker = $this->createMock(Worker::class);
        $watcher = $this->createWatcherWithSourceDir($worker, $tmpDir, ['*.php']);
        $this->setUpEventLoop();

        $watcher->start();
        $this->invokeDeferredWalk($watcher);

        // The watch follows the inode, so the kernel reports the same watch
        // descriptor for the new location; the old path must not linger.
        rename($tmpDir . '/alpha', $tmpDir . '/beta');
        clearstatcache();
        $this->waitForInotifyEvents();

        $fd = $this->getPrivateProperty($watcher, 'fd');
        $this->invokeOnNotify($watcher, $fd);

        $this->assertContains($tmpDir . '/beta', $this->getPrivateProperty($watcher, 'pathByWd'));
        $this->assertNotContains($tmpDir . '/alpha', $this->getPrivateProperty($watcher, 'pathByWd'));
        $watchedPaths = $this->getPrivateProperty($watcher, 'watchedPaths');
        $this->assertArrayHasKey($tmpDir . '/beta', $watchedPaths);
        $this->assertArrayNotHasKey($tmpDir . '/alpha', $watchedPaths);
        $this->assertMapsConsistent($watcher);
    }

    /**
     * @requires extension inotify
     */
    public function testFailedAddWatchWritesNoMapsAndLogsWarningOnce(): void
    {
        $tmpDir = $this->createTempDir();
        $logFile = $tmpDir . '/workerman.log';
        $originalLogFile = Worker::$logFile;
        $originalOutputStream = Worker::$outputStream;
        // Worker::log() -> safeEcho() requires a writable stream; in a unit
        // test no stream has been initialised, so give it one (same convention
        // as MasterWorkerTest::testFingerprintRenameFailureIsLogged).
        $testStream = \fopen('php://temp', 'w+');
        if ($testStream === false) {
            self::fail('Unable to open temp stream for test');
        }
        Worker::$outputStream = $testStream;
        Worker::$logFile = $logFile;

        try {
            $worker = $this->createMock(Worker::class);
            $watcher = $this->createWatcherWithSourceDir($worker, $tmpDir, ['*.php']);
            $this->setUpEventLoop();

            $watcher->start();

            $fd = $this->getPrivateProperty($watcher, 'fd');

            // Create a directory and remove it again before the queued event is
            // processed: the IN_CREATE|IN_ISDIR then hits a path that no longer
            // exists, so inotify_add_watch() fails.
            mkdir($tmpDir . '/ghost', 0700);
            $this->waitForInotifyEvents();
            rmdir($tmpDir . '/ghost');
            clearstatcache();
            $this->waitForInotifyEvents();

            $this->invokeOnNotify($watcher, $fd);

            $pathByWd = $this->getPrivateProperty($watcher, 'pathByWd');
            $this->assertSame(
                [$tmpDir],
                array_values($pathByWd),
                'a failed add_watch must not pollute pathByWd',
            );
            $this->assertArrayNotHasKey(
                $tmpDir . '/ghost',
                $this->getPrivateProperty($watcher, 'watchedPaths'),
                'a failed add_watch must not mark the path as watched',
            );

            $log = (string) file_get_contents($logFile);
            $this->assertStringContainsString('ghost', $log, 'the warning must name the failed path');
            $this->assertStringContainsString('max_user_watches', $log, 'the warning must point to the watch limit');

            // Repeat the same failing cycle: the warning is emitted once per path.
            mkdir($tmpDir . '/ghost', 0700);
            $this->waitForInotifyEvents();
            rmdir($tmpDir . '/ghost');
            clearstatcache();
            $this->waitForInotifyEvents();

            $this->invokeOnNotify($watcher, $fd);

            $log = (string) file_get_contents($logFile);
            $this->assertSame(1, substr_count($log, 'ghost'), 'the warning must be logged at most once per path');
        } finally {
            Worker::$logFile = $originalLogFile;
            Worker::$outputStream = $originalOutputStream;
        }
    }

    // ---- Helper methods ----

    private function setUpEventLoop(): void
    {
        $eventLoop = $this->createMock(EventInterface::class);
        $eventLoop->method('onReadable')->willReturnCallback(function (): void {
        });
        $eventLoop->method('delay')->willReturn(1);
        Worker::$globalEvent = $eventLoop;
    }

    private function waitForInotifyEvents(): void
    {
        usleep(200000);
    }

    private function createTempDir(): string
    {
        $this->tempDir = sys_get_temp_dir() . '/inotify_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0700, true);

        return $this->tempDir;
    }

    private function getPrivateProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }

    private function invokeIsFlagSet(InotifyMonitorWatcher $watcher, int $check, int $flag): bool
    {
        $reflection = new \ReflectionMethod(InotifyMonitorWatcher::class, 'isFlagSet');

        /** @var bool */
        return $reflection->invoke($watcher, $check, $flag);
    }

    private function invokeDeferredWalk(InotifyMonitorWatcher $watcher): void
    {
        $reflection = new \ReflectionMethod(InotifyMonitorWatcher::class, 'deferredWalk');
        $reflection->invoke($watcher);
    }

    private function invokeOnNotify(InotifyMonitorWatcher $watcher, mixed $fd): void
    {
        $reflection = new \ReflectionMethod(InotifyMonitorWatcher::class, 'onNotify');
        $reflection->invoke($watcher, $fd);
    }

    private function createWatcherInstance(): InotifyMonitorWatcher
    {
        $reflection = new \ReflectionClass(InotifyMonitorWatcher::class);

        return $reflection->newInstanceWithoutConstructor();
    }

    /**
     * @param string[] $filePattern
     */
    private function createWatcherWithSourceDir(
        Worker $worker,
        string $sourceDir,
        array $filePattern = ['*.php'],
    ): InotifyMonitorWatcher {
        $reflection = new \ReflectionClass(InotifyMonitorWatcher::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $parentClass = $reflection->getParentClass();
        if (!$parentClass instanceof \ReflectionClass) {
            throw new \RuntimeException('Failed to get parent class reflection');
        }

        $workerProp = $parentClass->getProperty('worker');
        $workerProp->setValue($instance, $worker);

        $sourceDirProp = $parentClass->getProperty('sourceDir');
        $sourceDirProp->setValue($instance, [$sourceDir]);

        $regexProp = $parentClass->getProperty('filePatternRegex');
        $compilePatterns = new \ReflectionMethod(FileMonitorWatcher::class, 'compilePatterns');
        $regexProp->setValue($instance, $compilePatterns->invoke($instance, $filePattern));

        return $instance;
    }

    private function assertMapsConsistent(InotifyMonitorWatcher $watcher): void
    {
        $pathByWd = $this->getPrivateProperty($watcher, 'pathByWd');
        $watchedPaths = $this->getPrivateProperty($watcher, 'watchedPaths');

        $this->assertCount(
            count($pathByWd),
            $watchedPaths,
            'pathByWd and watchedPaths must have equal sizes',
        );

        foreach ($pathByWd as $wd => $path) {
            $this->assertIsInt($wd, 'watch descriptors must be integers');
            $this->assertArrayHasKey($path, $watchedPaths, sprintf('path "%s" must be recorded as watched', $path));
        }
    }

    private function removeDirectory(string $dir): void
    {
        $files = \glob($dir . '/*');
        if (\is_array($files)) {
            foreach ($files as $file) {
                if (\is_dir($file)) {
                    $this->removeDirectory($file);
                } else {
                    \unlink($file);
                }
            }
        }
        @\rmdir($dir);
    }
}
