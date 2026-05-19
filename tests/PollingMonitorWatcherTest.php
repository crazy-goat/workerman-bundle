<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\PollingMonitorWatcher;
use PHPUnit\Framework\TestCase;
use Workerman\Worker;

final class PollingMonitorWatcherTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/workerman_polling_' . \bin2hex(\random_bytes(4));
        \mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
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
        \rmdir($dir);
    }

    /**
     * @param string[] $sourceDir
     * @param string[] $filePattern
     */
    private function createWatcher(
        Worker $worker,
        array $sourceDir,
        array $filePattern = ['*.php'],
    ): PollingMonitorWatcher {
        $reflection = new \ReflectionClass(PollingMonitorWatcher::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $parentClass = $reflection->getParentClass();
        if (!$parentClass instanceof \ReflectionClass) {
            throw new \RuntimeException('Failed to get parent class reflection');
        }

        $workerProp = $parentClass->getProperty('worker');
        $workerProp->setValue($instance, $worker);

        $sourceDirProp = $parentClass->getProperty('sourceDir');
        $sourceDirProp->setValue($instance, $sourceDir);

        $filePatternProp = $parentClass->getProperty('filePattern');
        $filePatternProp->setValue($instance, $filePattern);

        $lastMTimeProp = $reflection->getProperty('lastMTime');
        $lastMTimeProp->setValue($instance, \time());

        return $instance;
    }

    private function invokeCheckFileSystemChanges(PollingMonitorWatcher $watcher): void
    {
        $reflection = new \ReflectionMethod(PollingMonitorWatcher::class, 'checkFileSystemChanges');
        $reflection->invoke($watcher);
    }

    private function setLastMTime(PollingMonitorWatcher $watcher, int $mtime): void
    {
        $reflection = new \ReflectionProperty(PollingMonitorWatcher::class, 'lastMTime');
        $reflection->setValue($watcher, $mtime);
    }

    private function getLastMTime(PollingMonitorWatcher $watcher): int
    {
        $reflection = new \ReflectionProperty(PollingMonitorWatcher::class, 'lastMTime');

        return $reflection->getValue($watcher);
    }

    private function getTooManyFiles(PollingMonitorWatcher $watcher): bool
    {
        $reflection = new \ReflectionProperty(PollingMonitorWatcher::class, 'tooManyFiles');

        return $reflection->getValue($watcher);
    }

    public function testFileChangeDetectedAndLastMTimeUpdated(): void
    {
        $watchedFile = $this->tempDir . '/app.php';
        \file_put_contents($watchedFile, '<?php // v1');
        \touch($watchedFile, \time() - 10);

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher($worker, [$this->tempDir], ['*.php']);
        $targetTime = \time() - 5;
        $this->setLastMTime($watcher, $targetTime);

        \file_put_contents($watchedFile, '<?php // v2');
        \clearstatcache(true, $watchedFile);

        $this->invokeCheckFileSystemChanges($watcher);

        $this->assertGreaterThan(
            $targetTime,
            $this->getLastMTime($watcher),
            'lastMTime should be updated after detecting a changed file',
        );
    }

    public function testNoChangeWhenLastMTimeIsCurrent(): void
    {
        $watchedFile = $this->tempDir . '/app.php';
        \file_put_contents($watchedFile, '<?php // v1');

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $futureTime = \time() + 3600;
        $watcher = $this->createWatcher($worker, [$this->tempDir], ['*.php']);
        $this->setLastMTime($watcher, $futureTime);

        $this->invokeCheckFileSystemChanges($watcher);

        $this->assertSame(
            $futureTime,
            $this->getLastMTime($watcher),
            'lastMTime should remain unchanged when no file has been modified',
        );
    }

    public function testPatternMatchingSkipsNonMatchingFiles(): void
    {
        $nonWatchedFile = $this->tempDir . '/data.csv';
        \file_put_contents($nonWatchedFile, 'a,b,c');
        \touch($nonWatchedFile, \time() - 10);

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher($worker, [$this->tempDir], ['*.php']);
        $pastTime = \time() - 60;
        $this->setLastMTime($watcher, $pastTime);

        \file_put_contents($nonWatchedFile, 'd,e,f');
        \clearstatcache(true, $nonWatchedFile);

        $this->invokeCheckFileSystemChanges($watcher);

        $this->assertSame(
            $pastTime,
            $this->getLastMTime($watcher),
            'lastMTime should not be updated when a non-matching file changes',
        );
    }

    public function testDirectoriesAreSkipped(): void
    {
        $subDir = $this->tempDir . '/subdir';
        \mkdir($subDir, 0700);

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher($worker, [$this->tempDir], ['*']);
        $pastTime = \time() - 60;
        $this->setLastMTime($watcher, $pastTime);

        \touch($subDir, \time() + 10);
        \clearstatcache(true, $subDir);

        $this->invokeCheckFileSystemChanges($watcher);

        $this->assertSame(
            $pastTime,
            $this->getLastMTime($watcher),
            'lastMTime should not be updated when only directories change',
        );
    }

    public function testMultipleSourceDirsAreMonitored(): void
    {
        $dir2 = $this->tempDir . '/src2';
        \mkdir($dir2, 0700);

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher($worker, [$this->tempDir, $dir2], ['*.php']);

        $watchedFile2 = $dir2 . '/lib.php';
        \file_put_contents($watchedFile2, '<?php // v1');
        \touch($watchedFile2, \time() - 10);

        $targetTime = \time() - 5;
        $this->setLastMTime($watcher, $targetTime);

        \file_put_contents($watchedFile2, '<?php // v2');
        \clearstatcache(true, $watchedFile2);

        $this->invokeCheckFileSystemChanges($watcher);

        $this->assertGreaterThan(
            $targetTime,
            $this->getLastMTime($watcher),
            'lastMTime should be updated when a file in a secondary source dir changes',
        );
    }

    public function testDirectCallToGetMTimeNotGetFileInfo(): void
    {
        $source = \file_get_contents(__DIR__ . '/../src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php');
        \assert(\is_string($source));

        $this->assertStringNotContainsString(
            'getFileInfo',
            $source,
            'PollingMonitorWatcher should call getMTime() directly, not via getFileInfo()',
        );
    }

    public function testTooManyFilesWarningNotTriggeredWithFewFiles(): void
    {
        \file_put_contents($this->tempDir . '/a.php', '<?php');
        \file_put_contents($this->tempDir . '/b.php', '<?php');

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher($worker, [$this->tempDir], ['*.php']);

        $this->invokeCheckFileSystemChanges($watcher);

        $this->assertFalse(
            $this->getTooManyFiles($watcher),
            'tooManyFiles should remain false with few files',
        );
    }
}
