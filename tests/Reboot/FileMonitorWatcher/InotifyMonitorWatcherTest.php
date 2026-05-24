<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Reboot\FileMonitorWatcher;

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

    public function testOnNotifyIsNoOpWhenInotifyReadNotAvailable(): void
    {
        $watcher = $this->createWatcherInstance();

        $this->invokeOnNotify($watcher, null);

        $reloadCallback = $this->getPrivateProperty($watcher, 'reloadCallback');
        $this->assertNull($reloadCallback, 'reloadCallback should remain null when inotify_read is not available');
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
    public function testStartRecursivelyWatchesDirectories(): void
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
        $this->assertSame(1, $delayCount, 'First onNotify should schedule one reload');

        $this->invokeOnNotify($watcher, $fd);
        $this->assertSame(1, $delayCount, 'Second onNotify should NOT schedule another reload while callback is set');
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

        $pathByWd = $this->getPrivateProperty($watcher, 'pathByWd');

        $this->assertContains($tmpDir, $pathByWd, 'Root directory should be in pathByWd');
        $this->assertContains($tmpDir . '/alpha', $pathByWd, 'alpha subdirectory should be in pathByWd');
        $this->assertContains($tmpDir . '/beta', $pathByWd, 'beta subdirectory should be in pathByWd');

        foreach ($pathByWd as $wd => $path) {
            $this->assertIsInt($wd, 'Watch descriptor should be an integer');
            $this->assertDirectoryExists($path, 'Each path in pathByWd should be an existing directory');
        }
    }

    // ---- Helper methods ----

    private function setUpEventLoop(): void
    {
        $eventLoop = $this->createMock(EventInterface::class);
        $eventLoop->method('onReadable')->willReturnCallback(function (): void {
        });
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

        $filePatternProp = $parentClass->getProperty('filePattern');
        $filePatternProp->setValue($instance, $filePattern);

        return $instance;
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
