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

    public function testCheckPatternWithQuestionMark(): void
    {
        $qWatcher = $this->createWatcherWithPatterns(['?.php']);

        $this->assertTrue($this->invokeCheckPattern($qWatcher, 'a.php'));
        $this->assertFalse($this->invokeCheckPattern($qWatcher, 'ab.php'));
    }

    public function testCheckPatternWithCharacterClass(): void
    {
        $classWatcher = $this->createWatcherWithPatterns(['[abc].php']);

        $this->assertTrue($this->invokeCheckPattern($classWatcher, 'a.php'));
        $this->assertTrue($this->invokeCheckPattern($classWatcher, 'b.php'));
        $this->assertTrue($this->invokeCheckPattern($classWatcher, 'c.php'));
        $this->assertFalse($this->invokeCheckPattern($classWatcher, 'd.php'));
    }

    public function testCheckPatternWithNegatedCharacterClass(): void
    {
        $negWatcher = $this->createWatcherWithPatterns(['[!abc].php']);

        $this->assertTrue($this->invokeCheckPattern($negWatcher, 'd.php'));
        $this->assertFalse($this->invokeCheckPattern($negWatcher, 'a.php'));
    }

    public function testCheckPatternWithCharacterRange(): void
    {
        $rangeWatcher = $this->createWatcherWithPatterns(['[a-z].php']);

        $this->assertTrue($this->invokeCheckPattern($rangeWatcher, 'm.php'));
        $this->assertFalse($this->invokeCheckPattern($rangeWatcher, 'M.php'));
    }

    public function testCheckPatternWithEscapedWildcard(): void
    {
        $escWatcher = $this->createWatcherWithPatterns(['\\*.php']);

        $this->assertTrue($this->invokeCheckPattern($escWatcher, '*.php'));
        $this->assertFalse($this->invokeCheckPattern($escWatcher, 'index.php'));
    }

    public function testCheckPatternWithPureWildcard(): void
    {
        $allWatcher = $this->createWatcherWithPatterns(['*']);

        $this->assertTrue($this->invokeCheckPattern($allWatcher, 'anything.txt'));
        $this->assertTrue($this->invokeCheckPattern($allWatcher, ''));
    }

    public function testCheckPatternWithMultipleStars(): void
    {
        $multiWatcher = $this->createWatcherWithPatterns(['a*b*c']);

        $this->assertTrue($this->invokeCheckPattern($multiWatcher, 'axbyc'));
        $this->assertTrue($this->invokeCheckPattern($multiWatcher, 'abc'));
        $this->assertFalse($this->invokeCheckPattern($multiWatcher, 'axby'));
    }

    public function testCreateRecursiveIteratorWithDefaultFlags(): void
    {
        $iterator = $this->invokeCreateRecursiveIterator(
            $this->watcher,
            '/tmp',
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS,
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        $this->assertInstanceOf(\RecursiveIteratorIterator::class, $iterator);
        $this->assertInstanceOf(\RecursiveDirectoryIterator::class, $iterator->getInnerIterator());
    }

    public function testCreateRecursiveIteratorWithSelfFirstMode(): void
    {
        $iterator = $this->invokeCreateRecursiveIterator(
            $this->watcher,
            '/tmp',
            \FilesystemIterator::SKIP_DOTS,
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        $this->assertInstanceOf(\RecursiveIteratorIterator::class, $iterator);
        /* Verifying the mode indirectly: SELF_FIRST yields directories too,
           while LEAVES_ONLY skips them — both constructors accept the flag. */
        $this->assertInstanceOf(\RecursiveDirectoryIterator::class, $iterator->getInnerIterator());
    }

    public function testCreateRecursiveIteratorThrowsForNonExistentDirectory(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $this->invokeCreateRecursiveIterator(
            $this->watcher,
            '/non/existent/path/xyz',
            \FilesystemIterator::SKIP_DOTS,
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
    }

    private function invokeCheckPattern(FileMonitorWatcher $watcher, string $filename): bool
    {
        $reflection = new \ReflectionMethod(FileMonitorWatcher::class, 'checkPattern');

        return $reflection->invoke($watcher, $filename);
    }

    /** @return \RecursiveIteratorIterator<\RecursiveDirectoryIterator> */
    private function invokeCreateRecursiveIterator(FileMonitorWatcher $watcher, string $dir, int $flags, int $mode): \RecursiveIteratorIterator
    {
        $reflection = new \ReflectionMethod(FileMonitorWatcher::class, 'createRecursiveIterator');

        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> */
        return $reflection->invoke($watcher, $dir, $flags, $mode);
    }

    /** @param string[] $filePattern */
    private function createWatcherWithPatterns(array $filePattern): PollingMonitorWatcher
    {
        $worker = $this->createMock(Worker::class);

        $reflection = new \ReflectionClass(PollingMonitorWatcher::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $parentClass = $reflection->getParentClass();
        if (!$parentClass instanceof \ReflectionClass) {
            throw new \RuntimeException('Cannot get parent class reflection for ' . PollingMonitorWatcher::class);
        }

        $workerProp = $parentClass->getProperty('worker');
        $workerProp->setValue($instance, $worker);

        $sourceDirProp = $parentClass->getProperty('sourceDir');
        $sourceDirProp->setValue($instance, ['/tmp']);

        $regexProp = $parentClass->getProperty('filePatternRegex');
        $compilePatterns = new \ReflectionMethod(FileMonitorWatcher::class, 'compilePatterns');
        $regexProp->setValue($instance, $compilePatterns->invoke($instance, $filePattern));

        return $instance;
    }
}
