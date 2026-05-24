<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Worker;

use CrazyGoat\WorkermanBundle\Worker\FileMonitorWorker;
use PHPUnit\Framework\TestCase;
use Workerman\Worker;

final class FileMonitorWorkerTest extends TestCase
{
    /** @var array<string, Worker> */
    private array $initialWorkers;

    /** @var array<string, array<int, int>> */
    private array $initialPidMap;

    private mixed $savedGlobalEvent;

    protected function setUp(): void
    {
        $this->initialWorkers = $this->getWorkersProperty();
        $this->initialPidMap = $this->getPidMapProperty();
        $this->savedGlobalEvent = Worker::$globalEvent;
        Worker::$globalEvent = null;
    }

    protected function tearDown(): void
    {
        $workersRef = new \ReflectionProperty(Worker::class, 'workers');
        $workersRef->setValue(null, $this->initialWorkers);

        $pidMapRef = new \ReflectionProperty(Worker::class, 'pidMap');
        $pidMapRef->setValue(null, $this->initialPidMap);

        Worker::$globalEvent = $this->savedGlobalEvent;
    }

    public function testWorkerNameIsFileMonitor(): void
    {
        new FileMonitorWorker(null, null, ['/tmp'], ['*.php']);
        $this->assertSame('[FileMonitor]', $this->getNewWorker()->name);
    }

    public function testWorkerCountIsOne(): void
    {
        new FileMonitorWorker(null, null, ['/tmp'], ['*.php']);
        $this->assertSame(1, $this->getNewWorker()->count);
    }

    public function testWorkerIsNotReloadable(): void
    {
        new FileMonitorWorker(null, null, ['/tmp'], ['*.php']);
        $this->assertFalse($this->getNewWorker()->reloadable);
    }

    public function testNullUserAndGroupDefaultToEmptyString(): void
    {
        new FileMonitorWorker(null, null, ['/tmp'], ['*.php']);
        $worker = $this->getNewWorker();
        $this->assertSame('', $worker->user);
        $this->assertSame('', $worker->group);
    }

    public function testUserAndGroupArePassedToWorker(): void
    {
        new FileMonitorWorker('testuser', 'testgroup', ['/tmp'], ['*.php']);
        $worker = $this->getNewWorker();
        $this->assertSame('testuser', $worker->user);
        $this->assertSame('testgroup', $worker->group);
    }

    public function testOnWorkerStartClosureCapturesSourceDirAndFilePattern(): void
    {
        new FileMonitorWorker(null, null, ['/src', '/app'], ['*.php', '*.twig']);
        $callable = $this->getNewWorker()->onWorkerStart;
        $this->assertInstanceOf(\Closure::class, $callable);

        $ref = new \ReflectionFunction($callable);
        $vars = $ref->getStaticVariables();

        $this->assertSame(['/src', '/app'], $vars['sourceDir']);
        $this->assertSame(['*.php', '*.twig'], $vars['filePattern']);
    }

    public function testOnWorkerStartCreatesCorrectWatcherBasedOnExtension(): void
    {
        new FileMonitorWorker(null, null, ['/nonexistent'], ['*.php']);
        $callable = $this->getNewWorker()->onWorkerStart;
        $this->assertInstanceOf(\Closure::class, $callable);

        $worker = new Worker();
        $worker->name = '[FileMonitor]';

        $savedOutputStream = Worker::$outputStream;
        $savedLogFile = Worker::$logFile;
        $tempStream = fopen('php://memory', 'r+');
        if ($tempStream === false) {
            throw new \RuntimeException('Failed to open php://memory stream');
        }
        Worker::$outputStream = $tempStream;
        Worker::$logFile = '/dev/null';

        try {
            $callable($worker);
        } finally {
            Worker::$outputStream = $savedOutputStream;
            Worker::$logFile = $savedLogFile;
        }

        rewind($tempStream);
        $output = stream_get_contents($tempStream);
        fclose($tempStream);

        $this->assertStringContainsString('[FileMonitor] started', $output);

        if (\extension_loaded('inotify')) {
            $this->assertStringNotContainsString('Polling', $output);
        } else {
            $this->assertStringContainsString('Polling', $output);
        }
    }

    private function getNewWorker(): Worker
    {
        $workers = $this->getWorkersProperty();
        $newWorkers = \array_diff_key($workers, $this->initialWorkers);
        $this->assertNotEmpty($newWorkers, 'Expected at least one new worker to be created');

        return \reset($newWorkers);
    }

    /**
     * @return array<string, Worker>
     */
    private function getWorkersProperty(): array
    {
        $ref = new \ReflectionProperty(Worker::class, 'workers');

        return $ref->getValue();
    }

    /**
     * @return array<string, array<int, int>>
     */
    private function getPidMapProperty(): array
    {
        $ref = new \ReflectionProperty(Worker::class, 'pidMap');

        return $ref->getValue();
    }
}
