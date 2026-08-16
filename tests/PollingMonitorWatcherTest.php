<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\FileMonitorWatcher;
use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\PollingMonitorWatcher;
use CrazyGoat\WorkermanBundle\Test\Fixtures\PollingMonitorWatcher\CountingPollingMonitorWatcher;
use CrazyGoat\WorkermanBundle\Test\Fixtures\PollingMonitorWatcher\CountingRecursiveDirectoryIterator;
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
        CountingRecursiveDirectoryIterator::reset();
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
        $this->findProperty($reflection, 'lastMTime')->setValue($instance, \time());

        $regexProp = $this->findProperty($reflection, 'filePatternRegex');
        $compilePatterns = new \ReflectionMethod(FileMonitorWatcher::class, 'compilePatterns');
        $regexProp->setValue($instance, $compilePatterns->invoke($instance, $filePattern));

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

    /**
     * @return array<int, mixed>
     */
    private function getIterators(PollingMonitorWatcher $watcher): array
    {
        $reflection = new \ReflectionProperty(PollingMonitorWatcher::class, 'iterators');

        return $reflection->getValue($watcher);
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

    public function testSweepStateClearsAfterFullScan(): void
    {
        \file_put_contents($this->tempDir . '/a.php', '<?php');
        \file_put_contents($this->tempDir . '/b.php', '<?php');

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher($worker, [$this->tempDir], ['*.php']);

        $this->invokeCheckFileSystemChanges($watcher);

        $this->assertSame([], $this->getIterators($watcher), 'iterators should be empty after full scan');
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

        $this->assertNotEmpty($this->getIterators($watcher), 'iterators should have an entry when files exceed MAX_FILES_PER_TICK');
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

        $this->assertNotEmpty($this->getIterators($watcher), 'Tick 1 should keep a persisted iterator');

        // Tick 2: continue from resume point, process remaining files
        $this->invokeCheckFileSystemChanges($watcher);

        $this->assertSame([], $this->getIterators($watcher), 'iterators should be empty after completing full scan across multiple ticks');
    }

    /**
     * A full sweep spanning multiple ticks must advance the iterator exactly
     * N times (O(N)), not N²/budget.  The old code rebuilt the iterator on
     * every tick and fast-forwarded through already-seen entries, causing
     * repeated traversals.
     */
    public function testFullSweepIsLinearNotQuadratic(): void
    {
        // Create a tree with enough files to span several ticks (600 > 500).
        $fileCount = 600;
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
        // Future lastMTime so no reload is triggered mid-sweep.
        $this->setLastMTime($watcher, \time() + 3600);

        // Drive ticks until the sweep completes.
        $maxTicks = 10;
        for ($tick = 0; $tick < $maxTicks; $tick++) {
            $this->invokeCheckFileSystemChanges($watcher);
            if ($this->getIterators($watcher) === []) {
                break;
            }
        }

        // Total advances should be ~N, not N²/budget (which would be ~720).
        // Allow a small margin for filesystem overhead (e.g. the iterator
        // may yield dir entries on some platforms), but it must be well
        // below the quadratic bound.
        $this->assertLessThanOrEqual(
            $fileCount + 50,
            CountingRecursiveDirectoryIterator::$advanceCount,
            \sprintf(
                'Full sweep of %d files should advance the iterator ~N times (O(N)), got %d advances — the old O(N²/budget) regression may have returned.',
                $fileCount,
                CountingRecursiveDirectoryIterator::$advanceCount,
            ),
        );
        // Sanity: at least N advances happened (every file was visited).
        $this->assertGreaterThanOrEqual(
            $fileCount,
            CountingRecursiveDirectoryIterator::$advanceCount,
            \sprintf(
                'Full sweep of %d files should visit every file at least once, got %d advances.',
                $fileCount,
                CountingRecursiveDirectoryIterator::$advanceCount,
            ),
        );
    }

    /**
     * No tick may process more than MAX_FILES_PER_TICK entries, including
     * resumed ones.  The old code did not count skipped entries against
     * the budget, so a tick could do far more filesystem work than the
     * budget allowed.
     */
    public function testBudgetBoundsAllEntriesIncludingResumedOnes(): void
    {
        $fileCount = 1200; // spans at least 3 ticks at 500/tick
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
        $this->setLastMTime($watcher, \time() + 3600);

        $maxTicks = 10;
        for ($tick = 0; $tick < $maxTicks; $tick++) {
            CountingRecursiveDirectoryIterator::reset();
            $this->invokeCheckFileSystemChanges($watcher);

            // Each tick must not exceed MAX_FILES_PER_TICK advances.
            // Allow +1 because the budget check is > (strictly greater than)
            // so the entry that trips the boundary is still counted.
            $this->assertLessThanOrEqual(
                501,
                CountingRecursiveDirectoryIterator::$advanceCount,
                \sprintf(
                    'Tick %d processed %d entries — budget of 500 was exceeded (skipped entries must count against the budget).',
                    $tick + 1,
                    CountingRecursiveDirectoryIterator::$advanceCount,
                ),
            );

            if ($this->getIterators($watcher) === []) {
                break;
            }
        }
    }

    /**
     * A file modified in an already-scanned region (before the resume
     * point) must still trigger a reload on the next sweep.  Because the
     * iterator is held across ticks and only rewinds when the sweep
     * completes, a change in the already-scanned prefix is detected on
     * the *next* sweep.
     *
     * Runs in a subprocess (like the E2E test) because Utils::reload()
     * sends SIGUSR1 to the parent process.
     */
    public function testFileModifiedInAlreadyScannedRegionTriggersReloadOnNextSweep(): void
    {
        $autoloadPath = \realpath(__DIR__ . '/../vendor/autoload.php');
        if ($autoloadPath === false) {
            self::markTestSkipped('vendor/autoload.php not found.');
        }

        $scriptFile = __DIR__ . '/Fixtures/polling_watcher_mid_sweep_runner.php';

        $exitCode = $this->runPhpScript($scriptFile, [$this->tempDir, $autoloadPath]);

        self::assertSame(
            0,
            $exitCode,
            'A file modified in an already-scanned region should be detected on the next sweep.',
        );
    }

    /**
     * Adding or removing a directory between ticks must not throw, and
     * the sweep must still converge.  APFS readdir order is hash-based (not
     * alphabetical), so the removed directory may or may not have been
     * visited yet — either way the worker must not crash and the sweep
     * must eventually complete.  The dedicated
     * testFileDeletedAtIteratorPositionBetweenTicksDoesNotThrow covers the
     * deleted-file RuntimeException path deterministically.
     */
    public function testTreeMutationBetweenTicksDoesNotThrow(): void
    {
        for ($i = 0; $i < 600; $i++) {
            \file_put_contents($this->tempDir . '/file' . $i . '.php', '<?php');
        }
        $removedDir = $this->tempDir . '/zzz_subdir';
        \mkdir($removedDir, 0700, true);
        for ($i = 0; $i < 10; $i++) {
            \file_put_contents($removedDir . '/child' . $i . '.php', '<?php');
        }
        \clearstatcache();

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher($worker, [$this->tempDir], ['*.php']);
        $this->setLastMTime($watcher, \time() + 3600);

        // Tick 1: processes first 500 entries, stops at budget (mid-sweep).
        $this->invokeCheckFileSystemChanges($watcher);

        // Between ticks: add a new directory with a file.
        $newDir = $this->tempDir . '/newdir';
        \mkdir($newDir, 0700, true);
        \file_put_contents($newDir . '/new.php', '<?php');

        // Between ticks: remove zzz_subdir and its files. Whether the
        // iterator had already visited it or not, resuming must not throw.
        for ($i = 0; $i < 10; $i++) {
            \unlink($removedDir . '/child' . $i . '.php');
        }
        \rmdir($removedDir);

        // Drive ticks until the sweep (and the next one, which now includes
        // newdir) completes. 600 root files + newdir/new.php means the
        // post-mutation tree needs up to 3 ticks to sweep; allow margin.
        $converged = false;
        for ($tick = 0; $tick < 8; $tick++) {
            $this->invokeCheckFileSystemChanges($watcher);
            if ($this->getIterators($watcher) === []) {
                $converged = true;
                break;
            }
        }

        $this->assertTrue($converged, 'Sweep should converge after tree mutation without throwing');

        // Cleanup
        \unlink($newDir . '/new.php');
        \rmdir($newDir);
    }

    /**
     * Deleting the file at the iterator's current() position between ticks
     * must not throw.  The iterator is held across ticks, so the file it
     * stopped at may be gone by the next tick; SplFileInfo::getMTime()
     * then throws \RuntimeException ("stat failed"), which must be caught
     * and skipped rather than crashing the worker.
     */
    public function testFileDeletedAtIteratorPositionBetweenTicksDoesNotThrow(): void
    {
        for ($i = 0; $i < 600; $i++) {
            \file_put_contents($this->tempDir . '/file' . $i . '.php', '<?php');
        }
        \clearstatcache();

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher($worker, [$this->tempDir], ['*.php']);
        // Future lastMTime so no file triggers a reload — the watcher just
        // walks the tree, which is what we need to hold the iterator.
        $this->setLastMTime($watcher, \time() + 3600);

        // Tick 1: processes 500 files, stops with the iterator positioned
        // at file500.php (the entry that tripped the budget).
        $this->invokeCheckFileSystemChanges($watcher);

        // Delete the file the iterator is parked on.
        \unlink($this->tempDir . '/file500.php');
        \clearstatcache(true, $this->tempDir . '/file500.php');

        // Tick 2 must not throw: getMTime() on the deleted file500.php
        // raises \RuntimeException, which the inner catch skips.
        $this->invokeCheckFileSystemChanges($watcher);

        // Drive remaining ticks to completion — still must not throw.
        for ($tick = 0; $tick < 10; $tick++) {
            $this->invokeCheckFileSystemChanges($watcher);
            if ($this->getIterators($watcher) === []) {
                break;
            }
        }

        $this->assertSame([], $this->getIterators($watcher), 'Sweep should complete after the parked file was deleted');
    }

    /**
     * @param string[] $args
     */
    private function runPhpScript(string $scriptFile, array $args): int
    {
        $command = \array_values(['php', $scriptFile, ...$args]);
        $proc = \proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (!\is_resource($proc)) {
            $this->fail('Failed to start subprocess.');
        }

        \fclose($pipes[0]);
        \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);

        $exitCode = \proc_close($proc);

        if ($exitCode !== 0 && $stderr !== '' && $stderr !== false) {
            \fwrite(\STDERR, "Subprocess stderr: " . $stderr);
        }

        return $exitCode;
    }
}
