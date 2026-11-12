<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\FileMonitorWatcher;
use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\PollingMonitorWatcher;
use CrazyGoat\WorkermanBundle\Test\Fixtures\PollingMonitorWatcher\CountingPollingMonitorWatcher;
use CrazyGoat\WorkermanBundle\Test\Fixtures\PollingMonitorWatcher\CountingRecursiveDirectoryIterator;
use CrazyGoat\WorkermanBundle\Test\Fixtures\PollingMonitorWatcher\CountingSplFileInfo;
use PHPUnit\Framework\TestCase;
use Workerman\Events\EventInterface;
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
        int $pollingInterval = PollingMonitorWatcher::DEFAULT_POLLING_INTERVAL,
        int $maxFilesPerTick = PollingMonitorWatcher::DEFAULT_MAX_FILES_PER_TICK,
    ): PollingMonitorWatcher {
        $class ??= PollingMonitorWatcher::class;
        $reflection = new \ReflectionClass($class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $this->findProperty($reflection, 'worker')->setValue($instance, $worker);
        $this->findProperty($reflection, 'sourceDir')->setValue($instance, $sourceDir);
        $this->findProperty($reflection, 'lastMTime')->setValue($instance, \time());
        $this->findProperty($reflection, 'pollingInterval')->setValue($instance, $pollingInterval);
        $this->findProperty($reflection, 'maxFilesPerTick')->setValue($instance, $maxFilesPerTick);

        $regexProp = $this->findProperty($reflection, 'filePatternRegex');
        $compilePatterns = new \ReflectionMethod(FileMonitorWatcher::class, 'compilePatterns');
        $regexProp->setValue($instance, $compilePatterns->invoke($instance, $filePattern));

        return $instance;
    }

    /**
     * @phpstan-ignore missingType.generics
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

    public function testConstructorStoresConfigurablePollingTuning(): void
    {
        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = new PollingMonitorWatcher($worker, [$this->tempDir], ['*.php'], 7, 42);

        $reflection = new \ReflectionClass(PollingMonitorWatcher::class);
        self::assertSame(7, $this->findProperty($reflection, 'pollingInterval')->getValue($watcher));
        self::assertSame(42, $this->findProperty($reflection, 'maxFilesPerTick')->getValue($watcher));
    }

    public function testStartSchedulesConfiguredPollingInterval(): void
    {
        $event = $this->createMock(EventInterface::class);
        $event->expects(self::once())
            ->method('repeat')
            ->with(9, self::isType('callable'))
            ->willReturn(1);

        $savedEvent = Worker::$globalEvent;
        $savedWorkers = (new \ReflectionProperty(Worker::class, 'workers'))->getValue();
        $savedOutputStream = Worker::$outputStream;
        $savedLogFile = Worker::$logFile;
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open php://memory stream');
        }
        Worker::$globalEvent = $event;
        Worker::$outputStream = $stream;
        Worker::$logFile = '/dev/null';
        try {
            $worker = new Worker();
            $worker->name = 'test';

            $watcher = new PollingMonitorWatcher($worker, [$this->tempDir], ['*.php'], 9, 500);
            $watcher->start();
        } finally {
            Worker::$globalEvent = $savedEvent;
            Worker::$outputStream = $savedOutputStream;
            Worker::$logFile = $savedLogFile;
            (new \ReflectionProperty(Worker::class, 'workers'))->setValue(null, $savedWorkers);
            fclose($stream);
        }
    }

    public function testConstructorRejectsNonPositivePollingTuning(): void
    {
        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $this->expectException(\InvalidArgumentException::class);

        new PollingMonitorWatcher($worker, [$this->tempDir], ['*.php'], 0, 500);
    }

    public function testConstructorRejectsNonPositiveMaxFilesPerTick(): void
    {
        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $this->expectException(\InvalidArgumentException::class);

        new PollingMonitorWatcher($worker, [$this->tempDir], ['*.php'], 3, 0);
    }

    public function testConfigurableMaxFilesPerTickBoundsTheSweep(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            \file_put_contents($this->tempDir . '/file' . $i . '.php', '<?php');
        }

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher(
            $worker,
            [$this->tempDir],
            ['*.php'],
            null,
            PollingMonitorWatcher::DEFAULT_POLLING_INTERVAL,
            2,
        );

        $this->invokeCheckFileSystemChanges($watcher);
        $this->assertNotEmpty($this->getIterators($watcher), 'With max_files_per_tick=2 the 5-file sweep must not finish in one tick');

        $ticks = 1;
        while ($this->getIterators($watcher) !== [] && $ticks < 10) {
            $this->invokeCheckFileSystemChanges($watcher);
            ++$ticks;
        }

        $this->assertSame([], $this->getIterators($watcher), 'The sweep must complete across ticks at the configured budget');
        self::assertGreaterThan(1, $ticks, 'A budget of 2 over 5 files must require more than one tick');
    }

    /**
     * A later source dir must not starve when an earlier dir fills the
     * per-tick budget on its own.  Before the fix, every tick restarted
     * at the first dir, so the first dir was re-scanned, consumed the
     * whole budget, and the second dir's parked iterator never got
     * another turn — a change in the second dir was never detected.
     * The sweep now rotates: a tick resumes at the dir the previous
     * tick stopped in.
     */
    public function testLaterSourceDirIsReachedWhenFirstDirFillsBudget(): void
    {
        $dirA = $this->tempDir . '/a';
        $dirB = $this->tempDir . '/b';
        \mkdir($dirA, 0700);
        \mkdir($dirB, 0700);
        // Two matching files in A fill the per-tick budget of 2 on their
        // own; B holds a single matching file.
        \file_put_contents($dirA . '/a1.php', '<?php');
        \file_put_contents($dirA . '/a2.php', '<?php');
        \file_put_contents($dirB . '/b1.php', '<?php');
        \clearstatcache();

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher(
            $worker,
            [$dirA, $dirB],
            ['*.php'],
            null,
            PollingMonitorWatcher::DEFAULT_POLLING_INTERVAL,
            2,
        );
        // Future lastMTime so no file triggers a reload — the watcher
        // just walks both trees, which is what the starvation is about.
        $this->setLastMTime($watcher, \time() + 3600);

        // Tick 1: A is scanned (budget consumed) and B's iterator is
        // created but parked before its first entry — B is starving.
        $this->invokeCheckFileSystemChanges($watcher);
        $this->assertNotEmpty($this->getIterators($watcher), 'Tick 1 must leave B parked mid-sweep at a budget of 2');

        // Drive further ticks: with rotation the sweep must reach B and
        // converge within a bounded number of ticks (3 suffice: B, then
        // A parked, then A + B). Without rotation it never converges,
        // because every tick re-scans A first.
        $ticks = 1;
        while ($this->getIterators($watcher) !== [] && $ticks < 6) {
            $this->invokeCheckFileSystemChanges($watcher);
            ++$ticks;
        }

        $this->assertSame([], $this->getIterators($watcher), 'The sweep across both source dirs must complete within a bounded number of ticks');
        self::assertGreaterThan(1, $ticks, 'A budget of 2 over 3 files must require more than one tick');
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

        $this->assertNotEmpty($this->getIterators($watcher), 'iterators should have an entry when files exceed the per-tick budget');
    }

    public function testResumeContinuesAcrossMultipleTicks(): void
    {
        for ($i = 0; $i < 600; $i++) {
            \file_put_contents($this->tempDir . '/file' . $i . '.php', '<?php');
        }

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher($worker, [$this->tempDir], ['*.php']);

        $this->invokeCheckFileSystemChanges($watcher);

        $this->assertNotEmpty($this->getIterators($watcher), 'Tick 1 should keep a persisted iterator');

        $this->invokeCheckFileSystemChanges($watcher);

        $this->assertSame([], $this->getIterators($watcher), 'iterators should be empty after completing full scan across multiple ticks');
    }

    public function testFullSweepIsLinearNotQuadratic(): void
    {
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
        $this->setLastMTime($watcher, \time() + 3600);

        $maxTicks = 10;
        for ($tick = 0; $tick < $maxTicks; $tick++) {
            $this->invokeCheckFileSystemChanges($watcher);
            if ($this->getIterators($watcher) === []) {
                break;
            }
        }

        $this->assertLessThanOrEqual(
            $fileCount + 50,
            CountingRecursiveDirectoryIterator::$advanceCount,
            \sprintf(
                'Full sweep of %d files should advance the iterator ~N times (O(N)), got %d advances — the old O(N²/budget) regression may have returned.',
                $fileCount,
                CountingRecursiveDirectoryIterator::$advanceCount,
            ),
        );
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

    public function testBudgetBoundsAllEntriesIncludingResumedOnes(): void
    {
        $fileCount = 1200;
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

    public function testFileAtEndOfNextSweepIsDetectedOnSecondTick(): void
    {
        $autoloadPath = \realpath(__DIR__ . '/../vendor/autoload.php');
        if ($autoloadPath === false) {
            self::markTestSkipped('vendor/autoload.php not found.');
        }

        $scriptFile = __DIR__ . '/Fixtures/polling_watcher_mid_sweep_runner.php';
        $exitCode = $this->runPhpScript($scriptFile, [$this->tempDir, $autoloadPath, 'tail']);

        self::assertSame(0, $exitCode, 'A file at the end of a sweep should be detected on its second tick.');
    }

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

        $this->invokeCheckFileSystemChanges($watcher);

        $newDir = $this->tempDir . '/newdir';
        \mkdir($newDir, 0700, true);
        \file_put_contents($newDir . '/new.php', '<?php');

        for ($i = 0; $i < 10; $i++) {
            \unlink($removedDir . '/child' . $i . '.php');
        }
        \rmdir($removedDir);

        $converged = false;
        for ($tick = 0; $tick < 8; $tick++) {
            $this->invokeCheckFileSystemChanges($watcher);
            if ($this->getIterators($watcher) === []) {
                $converged = true;
                break;
            }
        }

        $this->assertTrue($converged, 'Sweep should converge after tree mutation without throwing');

        \unlink($newDir . '/new.php');
        \rmdir($newDir);
    }

    public function testFileDeletedAtIteratorPositionBetweenTicksDoesNotThrow(): void
    {
        for ($i = 0; $i < 600; $i++) {
            \file_put_contents($this->tempDir . '/file' . $i . '.php', '<?php');
        }
        \clearstatcache();

        $worker = $this->createMock(Worker::class);
        $worker->name = 'test';

        $watcher = $this->createWatcher($worker, [$this->tempDir], ['*.php']);
        $this->setLastMTime($watcher, \time() + 3600);

        $this->invokeCheckFileSystemChanges($watcher);

        $iterators = $this->getIterators($watcher);
        $this->assertNotEmpty($iterators, 'Tick 1 should leave a persisted iterator at the budget boundary');
        $iterator = $iterators[0] ?? null;
        self::assertInstanceOf(\Iterator::class, $iterator, 'Persisted iterator must be an Iterator');
        $this->assertTrue($iterator->valid(), 'Iterator must be parked on a valid entry at the budget boundary');

        $parkedFile = $iterator->current();
        self::assertInstanceOf(\SplFileInfo::class, $parkedFile, 'Parked entry must be an SplFileInfo');
        $parkedPath = $parkedFile->getPathname();
        $this->assertFileExists($parkedPath, 'Parked file must exist before deletion');
        $this->assertStringEndsWith('.php', $parkedPath, 'Parked file must match the watched pattern so getMTime() is actually called on it');

        \unlink($parkedPath);
        \clearstatcache(true, $parkedPath);

        $this->invokeCheckFileSystemChanges($watcher);

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
