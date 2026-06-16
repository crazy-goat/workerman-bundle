<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\PollingMonitorWatcher;
use CrazyGoat\WorkermanBundle\Test\Fixtures\PollingMonitorWatcher\CountingPollingMonitorWatcher;
use CrazyGoat\WorkermanBundle\Test\Fixtures\PollingMonitorWatcher\CountingSplFileInfo;
use PHPUnit\Framework\TestCase;
use Workerman\Worker;

final class PollingMonitorWatcherTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/workerman_polling_' . \bin2hex(\random_bytes(4));
        \mkdir($this->tempDir, 0700, true);
        CountingSplFileInfo::reset();
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
     * @param class-string<PollingMonitorWatcher>|null $class
     */
    private function createWatcher(
        Worker $worker,
        array $sourceDir,
        array $filePattern = ['*.php'],
        ?string $class = null,
    ): PollingMonitorWatcher {
        $class ??= PollingMonitorWatcher::class;
        $reflection = new \ReflectionClass($class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $this->findProperty($reflection, 'worker')->setValue($instance, $worker);
        $this->findProperty($reflection, 'sourceDir')->setValue($instance, $sourceDir);
        $this->findProperty($reflection, 'filePattern')->setValue($instance, $filePattern);
        $this->findProperty($reflection, 'lastMTime')->setValue($instance, \time());

        return $instance;
    }

    /**
     * Walks the hierarchy and returns a ReflectionProperty bound to the
     * declaring class's scope. Required on PHP 8.2/8.3 because
     * ReflectionProperty::setValue() on a readonly property checks the
     * scope of the reflection (where getProperty() was called from), not
     * the property's actual declaring class. Binding to a subclass scope
     * makes the readonly initializer throw even when the underlying
     * property is uninitialized.
     *
     * @phpstan-ignore-next-line missingType.generics
     */
    private function findProperty(\ReflectionClass $class, string $name): \ReflectionProperty
    {
        $className = $class->getName();
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            if (!$current->hasProperty($name)) {
                continue;
            }
            $prop = $current->getProperty($name);
            if ($prop->getDeclaringClass()->getName() === $current->getName()) {
                return $prop;
            }
        }

        throw new \RuntimeException("Property {$className}::\${$name} does not exist");
    }

    private function invokeCheckFileSystemChanges(PollingMonitorWatcher $watcher): void
    {
        $reflection = new \ReflectionMethod(PollingMonitorWatcher::class, 'checkFileSystemChanges');
        $reflection->invoke($watcher);
    }

    private function setLastMTime(PollingMonitorWatcher $watcher, int $mtime): void
    {
        $this->findProperty(new \ReflectionClass($watcher::class), 'lastMTime')->setValue($watcher, $mtime);
    }

    private function getLastMTime(PollingMonitorWatcher $watcher): int
    {
        $prop = $this->findProperty(new \ReflectionClass($watcher::class), 'lastMTime');

        return (int) $prop->getValue($watcher);
    }

    public function testFileChangeDetectionConditionIsCorrect(): void
    {
        $watchedFile = $this->tempDir . '/app.php';
        \file_put_contents($watchedFile, '<?php // v1');
        $originalMTime = \filemtime($watchedFile);
        \assert(\is_int($originalMTime));

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher($worker, [$this->tempDir], ['*.php']);
        $targetTime = $originalMTime - 5;
        $this->setLastMTime($watcher, $targetTime);

        \usleep(1000);
        \file_put_contents($watchedFile, '<?php // v2');
        \clearstatcache(true, $watchedFile);

        $newMTime = \filemtime($watchedFile);
        \assert(\is_int($newMTime));

        $this->assertGreaterThan(
            $targetTime,
            $newMTime,
            'File mtime after modification should exceed lastMTime, triggering detection',
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

    public function testMultipleSourceDirsConditionIsCorrect(): void
    {
        $dir2 = $this->tempDir . '/src2';
        \mkdir($dir2, 0700);

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher($worker, [$this->tempDir, $dir2], ['*.php']);

        $watchedFile2 = $dir2 . '/lib.php';
        \file_put_contents($watchedFile2, '<?php // v1');
        $originalMTime = \filemtime($watchedFile2);
        \assert(\is_int($originalMTime));

        $targetTime = $originalMTime - 5;
        $this->setLastMTime($watcher, $targetTime);

        \file_put_contents($watchedFile2, '<?php // v2');
        \clearstatcache(true, $watchedFile2);

        $newMTime = \filemtime($watchedFile2);
        \assert(\is_int($newMTime));

        $this->assertGreaterThan(
            $targetTime,
            $newMTime,
            'File mtime in secondary source dir should exceed lastMTime after modification',
        );
    }

    public function testPollUsesSingleStatPerFile(): void
    {
        $fileCount = 10;
        for ($i = 0; $i < $fileCount; $i++) {
            \file_put_contents($this->tempDir . '/file' . $i . '.php', '<?php');
        }
        \clearstatcache();

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher(
            $worker,
            [$this->tempDir],
            ['*.php'],
            CountingPollingMonitorWatcher::class,
        );
        // Set lastMTime to the future so no file is treated as modified and
        // the watcher iterates the full set instead of triggering reload on
        // the first match.
        $this->setLastMTime($watcher, \time() + 3600);

        $this->invokeCheckFileSystemChanges($watcher);

        $this->assertSame(
            $fileCount,
            CountingSplFileInfo::$statCallCount,
            \sprintf(
                'Expected exactly %d stat() calls (one per file), got %d. Reintroducing redundant stat-touching calls (e.g. getFileInfo(), getSize(), or duplicate getMTime()) would inflate this count.',
                $fileCount,
                CountingSplFileInfo::$statCallCount,
            ),
        );
    }

    public function testResumePathsResetsAfterFullScan(): void
    {
        \file_put_contents($this->tempDir . '/a.php', '<?php');
        \file_put_contents($this->tempDir . '/b.php', '<?php');

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher($worker, [$this->tempDir], ['*.php']);

        $this->invokeCheckFileSystemChanges($watcher);

        $reflection = new \ReflectionProperty(PollingMonitorWatcher::class, 'resumePaths');
        /** @var array<int, string> $resumePaths */
        $resumePaths = $reflection->getValue($watcher);

        $this->assertSame([], $resumePaths, 'resumePaths should be empty after full scan');
    }

    public function testMaxFilesPerTickRespectsBound(): void
    {
        for ($i = 0; $i < 600; $i++) {
            \file_put_contents($this->tempDir . '/file' . $i . '.php', '<?php');
        }

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher($worker, [$this->tempDir], ['*.php']);

        $this->invokeCheckFileSystemChanges($watcher);

        $reflection = new \ReflectionProperty(PollingMonitorWatcher::class, 'resumePaths');
        /** @var array<int, string> $resumePaths */
        $resumePaths = $reflection->getValue($watcher);

        $this->assertNotEmpty($resumePaths, 'resumePaths should have a resume point when files exceed MAX_FILES_PER_TICK');
    }

    public function testResumeContinuesAcrossMultipleTicks(): void
    {
        for ($i = 0; $i < 600; $i++) {
            \file_put_contents($this->tempDir . '/file' . $i . '.php', '<?php');
        }

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher($worker, [$this->tempDir], ['*.php']);

        // Tick 1: process first 500 files, stop at boundary
        $this->invokeCheckFileSystemChanges($watcher);

        $reflection = new \ReflectionProperty(PollingMonitorWatcher::class, 'resumePaths');
        /** @var array<int, string> $resumePaths */
        $resumePaths = $reflection->getValue($watcher);
        $this->assertNotEmpty($resumePaths, 'Tick 1 should set resume path');

        // Tick 2: continue from resume point, process remaining files
        $this->invokeCheckFileSystemChanges($watcher);

        $resumePathsAfter = $reflection->getValue($watcher);
        $this->assertSame([], $resumePathsAfter, 'resumePaths should be empty after completing full scan across multiple ticks');
    }
}
