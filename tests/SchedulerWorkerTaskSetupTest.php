<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Scheduler\TaskHandler;
use CrazyGoat\WorkermanBundle\Worker\SchedulerWorker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\KernelInterface;
use Workerman\Worker;

/**
 * Issue #970: one task that cannot be set up must not stop the other tasks.
 */
final class SchedulerWorkerTaskSetupTest extends TestCase
{
    private string $tmpDir;
    private string $savedLogFile;
    private string $savedPidFile;
    private mixed $savedOutputStream;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/workerman_scheduler_test_' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        $this->savedLogFile = Worker::$logFile;
        $this->savedPidFile = Worker::$pidFile;
        $this->savedOutputStream = (new \ReflectionProperty(Worker::class, 'outputStream'))->getValue();
        Worker::$logFile = $this->tmpDir . '/workerman.log';
        Worker::$pidFile = $this->tmpDir . '/workerman.pid';
        $stream = fopen('php://memory', 'w');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open memory stream');
        }
        Worker::$outputStream = $stream;
    }

    protected function tearDown(): void
    {
        Worker::$logFile = $this->savedLogFile;
        Worker::$pidFile = $this->savedPidFile;
        (new \ReflectionProperty(Worker::class, 'outputStream'))->setValue(null, $this->savedOutputStream);
        array_map(unlink(...), glob($this->tmpDir . '/*') ?: []);
        rmdir($this->tmpDir);
    }

    /**
     * @param array<string, array<string, mixed>> $config
     */
    private function scheduleTasks(array $config): string
    {
        $kernel = $this->createMock(KernelInterface::class);
        $worker = new SchedulerWorker(new KernelFactory(fn(): KernelInterface => $kernel, []), null, null, []);
        $handler = new TaskHandler(new ContainerBuilder(), new EventDispatcher());

        $method = new \ReflectionMethod(SchedulerWorker::class, 'scheduleTasks');
        $method->invoke($worker, $config, $handler);

        return (string) file_get_contents($this->tmpDir . '/workerman.log');
    }

    public function testStringJitterSkipsOnlyThatTask(): void
    {
        $log = $this->scheduleTasks([
            'a_bad_task' => ['schedule' => '1 second', 'jitter' => '30'],
            'b_good_task' => ['schedule' => '1 second'],
        ]);

        $this->assertStringContainsString('Task "a_bad_task" skipped', $log);
        $this->assertStringContainsString('TypeError', $log);
        $this->assertStringContainsString('Task "b_good_task" scheduled', $log);
    }

    public function testBadTaskBetweenGoodTasksDoesNotStopTheOthers(): void
    {
        $log = $this->scheduleTasks([
            'a_good_task' => ['schedule' => '1 second'],
            'b_bad_task' => ['schedule' => '1 second', 'jitter' => 'x'],
            'c_good_task' => ['schedule' => '1 second', 'jitter' => 5],
        ]);

        $this->assertStringContainsString('Task "a_good_task" scheduled', $log);
        $this->assertStringContainsString('Task "b_bad_task" skipped', $log);
        $this->assertStringContainsString('Task "c_good_task" scheduled', $log);
    }
}
