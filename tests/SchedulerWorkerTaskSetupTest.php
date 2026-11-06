<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Scheduler\TaskHandler;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\PeriodicalTrigger;
use CrazyGoat\WorkermanBundle\Util\ServiceMethod;
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

    public function testSchedulerStartKeepsARegularTaskPid(): void
    {
        $service = new ServiceMethod('test.locked.service', '__invoke');
        $pidFile = sprintf(
            '%s/workerman.task.%s.pid',
            dirname(Worker::$pidFile),
            hash('xxh64', $service->toString()),
        );
        file_put_contents($pidFile, '123');

        $fp = fopen($pidFile, 'c');
        $this->assertIsResource($fp);
        $this->assertTrue(flock($fp, LOCK_EX | LOCK_NB));

        try {
            $log = $this->scheduleTasks([
                'test.locked.service' => ['schedule' => '1 second'],
            ]);

            $this->assertFileExists($pidFile, 'A locked PID file belongs to a live task child and must stay');
            $this->assertSame('123', file_get_contents($pidFile), 'The start path must not touch a live task PID file');
            $this->assertStringContainsString('scheduled', $log);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($pidFile);
        }
    }

    public function testLockedTaskPidSkipsTheRun(): void
    {
        $service = new ServiceMethod('test.skip.service', '__invoke');
        $pidFile = sprintf(
            '%s/workerman.task.%s.pid',
            dirname(Worker::$pidFile),
            hash('xxh64', $service->toString()),
        );
        file_put_contents($pidFile, '123');

        $fp = fopen($pidFile, 'c');
        $this->assertIsResource($fp);
        $this->assertTrue(flock($fp, LOCK_EX | LOCK_NB));

        $marker = $this->tmpDir . '/handler-ran';
        $container = new class ($marker) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly string $marker)
            {
            }

            public function get(string $id): mixed
            {
                $marker = $this->marker;

                return new class ($marker) {
                    public function __construct(private readonly string $marker)
                    {
                    }

                    public function __invoke(): void
                    {
                        file_put_contents($this->marker, 'ran');
                    }
                };
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $handler = new TaskHandler($container, new EventDispatcher());

        try {
            $worker = new SchedulerWorker(new KernelFactory(fn(): KernelInterface => $this->createMock(KernelInterface::class), []), null, null, []);
            $method = new \ReflectionMethod(SchedulerWorker::class, 'runCallback');
            $method->invoke($worker, new PeriodicalTrigger(1), $service, 'task', $handler);

            $this->assertFileDoesNotExist($marker, 'A locked task must not start a second run');
            $this->assertSame('123', file_get_contents($pidFile), 'The running task PID file must not be overwritten');
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($pidFile);
        }
    }

    public function testSchedulerStartRemovesAPidFileSymlinkWithoutFollowingIt(): void
    {
        $service = new ServiceMethod('test.symlink.service', '__invoke');
        $pidFile = sprintf(
            '%s/workerman.task.%s.pid',
            dirname(Worker::$pidFile),
            hash('xxh64', $service->toString()),
        );
        $target = $this->tmpDir . '/symlink-target';
        file_put_contents($target, 'keep');
        symlink($target, $pidFile);

        $this->scheduleTasks([
            'test.symlink.service' => ['schedule' => '1 second'],
        ]);

        $this->assertFileDoesNotExist($pidFile);
        $this->assertFileExists($target, 'The symlink target must never be removed');
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
