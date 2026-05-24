<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Worker;

use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Scheduler\TaskHandler;
use CrazyGoat\WorkermanBundle\Worker\SchedulerWorker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Workerman\Worker;

/**
 * Tests for SchedulerWorker.
 *
 * Structural tests verify constructor behavior and closure configuration.
 * Behavioral tests (fork, flock, PID lifecycle) run in isolated PHP processes
 * via proc_open to avoid PHPUnit state interference with pcntl_fork().
 */

final class SchedulerWorkerTest extends TestCase
{
    private KernelFactory $kernelFactory;

    /** @var array<string, Worker> */
    private array $initialWorkers;

    /** @var array<string, array<int, int>> */
    private array $initialPidMap;

    private mixed $savedGlobalEvent;

    protected function setUp(): void
    {
        $this->kernelFactory = $this->createKernelFactory();

        $this->initialWorkers = $this->getWorkersProperty();
        $this->initialPidMap = $this->getPidMapProperty();
        $this->savedGlobalEvent = Worker::$globalEvent;
        Worker::$globalEvent = null;
    }

    private function createKernelFactory(): KernelFactory
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $psrContainer = $this->createMock(\Psr\Container\ContainerInterface::class);
        $handler = new TaskHandler($psrContainer, $dispatcher);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with('workerman.task_handler')
            ->willReturn($handler);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getContainer')->willReturn($container);

        return new KernelFactory(
            fn(): KernelInterface => $kernel,
            [],
        );
    }

    protected function tearDown(): void
    {
        $workersRef = new \ReflectionProperty(Worker::class, 'workers');
        $workersRef->setValue(null, $this->initialWorkers);

        $pidMapRef = new \ReflectionProperty(Worker::class, 'pidMap');
        $pidMapRef->setValue(null, $this->initialPidMap);

        Worker::$globalEvent = $this->savedGlobalEvent;
    }

    public function testWorkerNameIsScheduler(): void
    {
        new SchedulerWorker($this->kernelFactory, null, null, []);
        $this->assertSame('[Scheduler]', $this->getNewWorker()->name);
    }

    public function testWorkerCountIsOne(): void
    {
        new SchedulerWorker($this->kernelFactory, null, null, []);
        $this->assertSame(1, $this->getNewWorker()->count);
    }

    public function testWorkerIsReloadable(): void
    {
        new SchedulerWorker($this->kernelFactory, null, null, []);
        $this->assertTrue($this->getNewWorker()->reloadable);
    }

    public function testNullUserAndGroupDefaultToEmptyString(): void
    {
        new SchedulerWorker($this->kernelFactory, null, null, []);
        $worker = $this->getNewWorker();
        $this->assertSame('', $worker->user);
        $this->assertSame('', $worker->group);
    }

    public function testUserAndGroupArePassedToWorker(): void
    {
        new SchedulerWorker($this->kernelFactory, 'testuser', 'testgroup', []);
        $worker = $this->getNewWorker();
        $this->assertSame('testuser', $worker->user);
        $this->assertSame('testgroup', $worker->group);
    }

    public function testOnWorkerStartClosureCapturesKernelFactory(): void
    {
        new SchedulerWorker($this->kernelFactory, null, null, []);
        $vars = $this->getNewWorkerClosureVars();
        $this->assertArrayHasKey('kernelFactory', $vars);
        $this->assertSame($this->kernelFactory, $vars['kernelFactory']);
    }

    public function testOnWorkerStartClosureCapturesSchedulerConfig(): void
    {
        $config = ['task1' => ['schedule' => 5]];
        new SchedulerWorker($this->kernelFactory, null, null, $config);
        $vars = $this->getNewWorkerClosureVars();
        $this->assertArrayHasKey('schedulerConfig', $vars);
        $this->assertSame($config, $vars['schedulerConfig']);
    }

    public function testOnWorkerStartLogsStartedMessage(): void
    {
        new SchedulerWorker($this->kernelFactory, null, null, []);
        $callable = $this->getNewWorker()->onWorkerStart;
        $this->assertInstanceOf(\Closure::class, $callable);

        $worker = new Worker();

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

        $this->assertStringContainsString('[Scheduler] started', $output);
    }

    public function testTaskNameDefaultsToServiceIdWhenNameNotSet(): void
    {
        $output = $this->invokeOnWorkerStartWithConfig(
            ['my_service' => ['schedule' => 5]],
        );

        $this->assertStringContainsString(
            'Task "my_service" scheduled',
            $output,
            'Task name should default to service ID when no name is configured',
        );
    }

    public function testTaskNameUsesExplicitNameWhenSet(): void
    {
        $output = $this->invokeOnWorkerStartWithConfig(
            ['my_service' => ['name' => 'explicit-task', 'schedule' => 5]],
        );

        $this->assertStringContainsString(
            'Task "explicit-task" scheduled',
            $output,
            'Task name should use the configured name',
        );
    }

    public function testDefaultMethodIsInvoke(): void
    {
        new SchedulerWorker(
            $this->kernelFactory,
            null,
            null,
            ['my_service' => ['schedule' => 5]],
        );

        $vars = $this->getNewWorkerClosureVars();
        $config = $vars['schedulerConfig']['my_service'];
        $this->assertArrayNotHasKey(
            'method',
            $config,
            'Default method should be __invoke when no method is configured',
        );
    }

    public function testExplicitMethodIsUsed(): void
    {
        new SchedulerWorker(
            $this->kernelFactory,
            null,
            null,
            ['my_service' => ['method' => 'customMethod', 'schedule' => 5]],
        );

        $vars = $this->getNewWorkerClosureVars();
        $this->assertSame(
            'customMethod',
            $vars['schedulerConfig']['my_service']['method'],
            'Explicit method should be used when configured',
        );
    }

    public function testJobConfigActiveByDefault(): void
    {
        $output = $this->invokeOnWorkerStartWithConfig(
            ['remote_kill' => ['schedule' => 5]],
        );

        $this->assertStringContainsString(
            'Task "remote_kill" scheduled. Trigger',
            $output,
            'Task should be scheduled with default name when active is not specified',
        );
    }

    // --- Behavioral tests via isolated runner ---

    private const RUNNER_SCRIPT = __DIR__ . '/../Fixtures/scheduler_worker_runner.php';

    public function testForkSuccessInvokesHandlerAndCleansUp(): void
    {
        $this->runIsolatedTest('fork_success');
    }

    public function testForkErrorReleasesLock(): void
    {
        $this->runIsolatedTest('fork_error');
    }

    public function testLockContentionDoesNotFork(): void
    {
        $this->runIsolatedTest('lock_contention');
    }

    public function testPidFileLifecycleManagedCorrectly(): void
    {
        $this->runIsolatedTest('pid_lifecycle');
    }

    /**
     * @param array<string, array<string, mixed>> $config
     */
    private function invokeOnWorkerStartWithConfig(array $config): string
    {
        new SchedulerWorker($this->kernelFactory, null, null, $config);
        $callable = $this->getNewWorker()->onWorkerStart;
        $this->assertInstanceOf(\Closure::class, $callable);

        $worker = new Worker();

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

        return $output;
    }

    /**
     * Run a SchedulerWorker test in an isolated PHP process to avoid
     * PHPUnit state inheritance issues with pcntl_fork().
     */
    private function runIsolatedTest(string $testName): void
    {
        $this->assertFileExists(self::RUNNER_SCRIPT, 'Test runner script must exist');

        $tempDir = sys_get_temp_dir() . '/workerman_scheduler_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [
                PHP_BINARY,
                self::RUNNER_SCRIPT,
                $testName,
                __DIR__ . '/../../vendor/autoload.php',
                $tempDir,
            ],
            $descriptors,
            $pipes,
        );

        $this->assertIsResource($process, 'Failed to start isolated test process');

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $this->assertSame(
            0,
            $exitCode,
            sprintf(
                "Isolated test '%s' failed (exit code %d):\nstdout: %s\nstderr: %s",
                $testName,
                $exitCode,
                $stdout,
                $stderr,
            ),
        );

        $this->assertStringContainsString('PASS', $stdout);

        $this->cleanupDir($tempDir);
    }

    private function cleanupDir(string $dir): void
    {
        $files = glob($dir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_dir($file)) {
                    $subFiles = glob($file . '/*');
                    if (is_array($subFiles)) {
                        foreach ($subFiles as $subFile) {
                            @unlink($subFile);
                        }
                    }
                    @rmdir($file);
                } else {
                    @unlink($file);
                }
            }
        }
        @rmdir($dir);
    }

    private function getNewWorker(): Worker
    {
        $workers = $this->getWorkersProperty();
        $newWorkers = \array_diff_key($workers, $this->initialWorkers);
        $this->assertNotEmpty($newWorkers, 'Expected at least one new worker to be created');

        return \reset($newWorkers);
    }

    /**
     * @return array<string, mixed>
     */
    private function getNewWorkerClosureVars(): array
    {
        $callable = $this->getNewWorker()->onWorkerStart;
        $this->assertInstanceOf(\Closure::class, $callable);
        $closureRef = new \ReflectionFunction($callable);

        return $closureRef->getStaticVariables();
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
