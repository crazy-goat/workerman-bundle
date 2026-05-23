<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Reboot\FileMonitorWatcher;

use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\FileMonitorWatcher;
use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\InotifyMonitorWatcher;
use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\PollingMonitorWatcher;
use PHPUnit\Framework\TestCase;
use Workerman\Worker;

/** @psalm-suppress PropertyNotSetInConstructor */
final class FileMonitorWatcherTest extends TestCase
{
    private FileMonitorWatcher $watcher;

    protected function setUp(): void
    {
        $this->watcher = $this->createWatcherWithPatterns(['*.php']);
    }

    public function testCreateReturnsInotifyMonitorWatcherWhenInotifyExtensionLoaded(): void
    {
        $worker = $this->createMock(Worker::class);
        $watcher = FileMonitorWatcher::create($worker, ['/tmp'], ['*.php']);

        if (\extension_loaded('inotify')) {
            $this->assertInstanceOf(InotifyMonitorWatcher::class, $watcher);
        } else {
            $this->assertInstanceOf(PollingMonitorWatcher::class, $watcher);
        }
    }

    public function testCheckPatternMatchesSimpleGlob(): void
    {
        $this->assertTrue($this->invokeCheckPattern($this->watcher, 'index.php'));
    }

    public function testCheckPatternDoesNotMatchNonMatchingFile(): void
    {
        $this->assertFalse($this->invokeCheckPattern($this->watcher, 'data.csv'));
    }

    public function testCheckPatternWithEmptyPatternList(): void
    {
        $emptyWatcher = $this->createWatcherWithPatterns([]);

        $this->assertFalse($this->invokeCheckPattern($emptyWatcher, 'index.php'));
    }

    public function testCheckPatternWithMultiplePatterns(): void
    {
        $multiWatcher = $this->createWatcherWithPatterns(['*.php', '*.twig']);

        $this->assertTrue($this->invokeCheckPattern($multiWatcher, 'template.twig'));
        $this->assertTrue($this->invokeCheckPattern($multiWatcher, 'index.php'));
        $this->assertFalse($this->invokeCheckPattern($multiWatcher, 'data.csv'));
    }

    public function testCheckPatternHandlesHiddenFiles(): void
    {
        $hiddenWatcher = $this->createWatcherWithPatterns(['.*', '*.env']);

        $this->assertTrue($this->invokeCheckPattern($hiddenWatcher, '.env'));
        $this->assertTrue($this->invokeCheckPattern($hiddenWatcher, '.gitignore'));
    }

    public function testCheckPatternWithDirectorySeparator(): void
    {
        $dirWatcher = $this->createWatcherWithPatterns(['src/*.php']);

        $this->assertTrue($this->invokeCheckPattern($dirWatcher, 'src/Controller.php'));
        $this->assertFalse($this->invokeCheckPattern($dirWatcher, 'Controller.php'));
    }

    public function testCheckPatternRegressionNoFalsePositive(): void
    {
        $this->assertFalse($this->invokeCheckPattern($this->watcher, 'file_without_extension'));
        $this->assertFalse($this->invokeCheckPattern($this->watcher, 'file.txt'));
        $this->assertFalse($this->invokeCheckPattern($this->watcher, 'file.php.bak'));
    }

    private function invokeCheckPattern(FileMonitorWatcher $watcher, string $filename): bool
    {
        $reflection = new \ReflectionMethod(FileMonitorWatcher::class, 'checkPattern');

        return $reflection->invoke($watcher, $filename);
    }

    /** @param string[] $filePattern */
    private function createWatcherWithPatterns(array $filePattern): PollingMonitorWatcher
    {
        $worker = $this->createMock(Worker::class);

        $reflection = new \ReflectionClass(PollingMonitorWatcher::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $parentClass = $reflection->getParentClass();
        \assert($parentClass instanceof \ReflectionClass);

        $workerProp = $parentClass->getProperty('worker');
        $workerProp->setValue($instance, $worker);

        $sourceDirProp = $parentClass->getProperty('sourceDir');
        $sourceDirProp->setValue($instance, ['/tmp']);

        $filePatternProp = $parentClass->getProperty('filePattern');
        $filePatternProp->setValue($instance, $filePattern);

        return $instance;
    }
}
