<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Worker;

use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Worker\RestartBackoff;
use CrazyGoat\WorkermanBundle\Worker\SupervisorWorker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Workerman\Worker;

final class SupervisorWorkerTest extends TestCase
{
    private KernelFactory $kernelFactory;

    /** @var array<string, Worker> */
    private array $initialWorkers;

    /** @var array<string, array<int, int>> */
    private array $initialPidMap;

    protected function setUp(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $this->kernelFactory = new KernelFactory(
            fn(): KernelInterface => $kernel,
            [],
        );

        $this->initialWorkers = $this->getWorkersProperty();
        $this->initialPidMap = $this->getPidMapProperty();
    }

    protected function tearDown(): void
    {
        $workersRef = new \ReflectionProperty(Worker::class, 'workers');
        $workersRef->setValue(null, $this->initialWorkers);

        $pidMapRef = new \ReflectionProperty(Worker::class, 'pidMap');
        $pidMapRef->setValue(null, $this->initialPidMap);
    }

    public function testNonArrayConfigEntryIsSkipped(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            ['service1' => 'not_an_array'],
        );

        $this->assertSame($this->initialWorkers, $this->getWorkersProperty());
    }

    public function testProcessesZeroEntryIsSkipped(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            ['service1' => ['processes' => 0]],
        );

        $this->assertSame($this->initialWorkers, $this->getWorkersProperty());
    }

    public function testProcessesNegativeEntryIsSkipped(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            ['service1' => ['processes' => -1]],
        );

        $this->assertSame($this->initialWorkers, $this->getWorkersProperty());
    }

    public function testValidConfigCreatesWorker(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            ['service1' => ['name' => 'test-process']],
        );

        $this->assertCount(\count($this->initialWorkers) + 1, $this->getWorkersProperty());
    }

    public function testMultipleValidConfigsCreateMultipleWorkers(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            [
                'service1' => ['name' => 'process-1'],
                'service2' => ['name' => 'process-2'],
            ],
        );

        $this->assertCount(\count($this->initialWorkers) + 2, $this->getWorkersProperty());
    }

    public function testMixedConfigSkipsInvalidEntries(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            [
                'service1' => ['name' => 'valid'],
                'service2' => 'not_an_array',
                'service3' => ['processes' => 0],
                'service4' => ['processes' => -5],
                'service5' => ['name' => 'also-valid'],
            ],
        );

        $this->assertCount(\count($this->initialWorkers) + 2, $this->getWorkersProperty());
    }

    public function testWorkerCountDefaultsToOne(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            ['service1' => []],
        );

        $worker = $this->getNewWorker();
        $this->assertSame(1, $worker->count);
    }

    public function testWorkerCountIsSetFromConfig(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            ['service1' => ['processes' => 5]],
        );

        $worker = $this->getNewWorker();
        $this->assertSame(5, $worker->count);
    }

    public function testNullUserAndGroupDefaultToEmptyString(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            ['service1' => []],
        );

        $worker = $this->getNewWorker();
        $this->assertSame('', $worker->user);
        $this->assertSame('', $worker->group);
    }

    public function testUserAndGroupArePassedToWorker(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            'testuser',
            'testgroup',
            ['service1' => ['name' => 'test']],
        );

        $worker = $this->getNewWorker();
        $this->assertSame('testuser', $worker->user);
        $this->assertSame('testgroup', $worker->group);
    }

    public function testWorkerNameIsSetToProcessTitle(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            ['service1' => []],
        );

        $worker = $this->getNewWorker();
        $this->assertSame('[Process]', $worker->name);
    }

    public function testTaskNameDefaultsToServiceIdWhenNameNotSet(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            ['my_service' => []],
        );

        $closureVars = $this->getNewWorkerClosureVars();
        $this->assertSame('my_service', $closureVars['taskName']);
    }

    public function testTaskNameUsesExplicitNameWhenSet(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            ['my_service' => ['name' => 'explicit-task']],
        );

        $closureVars = $this->getNewWorkerClosureVars();
        $this->assertSame('explicit-task', $closureVars['taskName']);
    }

    public function testRestartBackoffRunsBeforeKernelBoot(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->expects($this->exactly(2))
            ->method('boot')
            ->willThrowException(new \RuntimeException('boot failed'));
        $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);

        $runtimeDir = sys_get_temp_dir() . '/supervisor-backoff-' . uniqid();
        mkdir($runtimeDir, 0700, true);
        $logFile = $runtimeDir . '/workerman.log';
        $logFileProperty = new \ReflectionProperty(Worker::class, 'logFile');
        $previousLogFile = $logFileProperty->getValue();
        $logFileProperty->setValue(null, $logFile);

        try {
            new SupervisorWorker(
                $kernelFactory,
                null,
                null,
                ['service1' => ['name' => 'test-process']],
                ['delay' => 1, 'max_delay' => 60, 'max_failures' => 10, 'healthy_after' => 30],
                $runtimeDir,
            );

            $worker = $this->getNewWorker();
            $callable = $worker->onWorkerStart;
            $this->assertInstanceOf(\Closure::class, $callable);

            try {
                $callable($worker);
                $this->fail('The kernel boot exception must escape the worker start callback');
            } catch (\RuntimeException $e) {
                $this->assertSame('boot failed', $e->getMessage());
            }

            $start = microtime(true);
            try {
                $callable($worker);
                $this->fail('The kernel boot exception must escape the second start too');
            } catch (\RuntimeException $e) {
                $this->assertSame('boot failed', $e->getMessage());
            }
            $elapsed = microtime(true) - $start;
            $this->assertGreaterThan(0.8, $elapsed, 'The second start must wait before booting the kernel');

            $stateFiles = glob($runtimeDir . '/restart/*.json');
            $this->assertIsArray($stateFiles);
            $this->assertCount(1, $stateFiles, 'The backoff state must be written before the kernel boots');

            $state = json_decode((string) file_get_contents($stateFiles[0]), true);
            $this->assertIsArray($state);
            $this->assertSame(2, $state['failures']);
        } finally {
            $logFileProperty->setValue(null, $previousLogFile);
            foreach (glob($runtimeDir . '/restart/*.json') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($runtimeDir . '/restart');
            @unlink($logFile);
            @rmdir($runtimeDir);
        }
    }

    public function testOnWorkerStartClosureCarriesRestartBackoff(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            ['service1' => ['name' => 'test-process', 'restart_delay' => 2, 'max_restart_delay' => 10]],
            ['delay' => 1, 'max_delay' => 60, 'max_failures' => 10, 'healthy_after' => 30],
            sys_get_temp_dir(),
        );

        $closureVars = $this->getNewWorkerClosureVars();
        $this->assertArrayHasKey('backoff', $closureVars);
        $this->assertInstanceOf(RestartBackoff::class, $closureVars['backoff']);

        $backoff = $closureVars['backoff'];
        $this->assertSame(2, $backoff->delayForFailures(1), 'restart_delay must override the global delay');
        $this->assertSame(10, $backoff->delayForFailures(100), 'max_restart_delay must cap the wait');
    }

    public function testIntentionalReloadForgetsTheFailureCount(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->expects($this->exactly(2))
            ->method('boot')
            ->willThrowException(new \RuntimeException('boot failed'));
        $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);

        $runtimeDir = sys_get_temp_dir() . '/supervisor-reload-' . uniqid();
        mkdir($runtimeDir, 0700, true);
        $logFileProperty = new \ReflectionProperty(Worker::class, 'logFile');
        $previousLogFile = $logFileProperty->getValue();
        $logFileProperty->setValue(null, $runtimeDir . '/workerman.log');

        try {
            new SupervisorWorker(
                $kernelFactory,
                null,
                null,
                ['service1' => ['name' => 'test-process']],
                ['delay' => 1, 'max_delay' => 60, 'max_failures' => 10, 'healthy_after' => 30],
                $runtimeDir,
            );

            $worker = $this->getNewWorker();
            $callable = $worker->onWorkerStart;
            $this->assertInstanceOf(\Closure::class, $callable);
            $reload = $worker->onWorkerReload;
            $this->assertInstanceOf(\Closure::class, $reload);

            try {
                $callable($worker);
            } catch (\RuntimeException) {
            }

            $reload($worker);

            $start = microtime(true);
            try {
                $callable($worker);
            } catch (\RuntimeException) {
            }
            $this->assertLessThan(0.5, microtime(true) - $start, 'A reload must reset the failure counter');
        } finally {
            $logFileProperty->setValue(null, $previousLogFile);
            foreach (glob($runtimeDir . '/restart/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($runtimeDir . '/restart');
            @unlink($runtimeDir . '/workerman.log');
            @rmdir($runtimeDir);
        }
    }

    public function testOnWorkerStartClosureHasDefaultMethodWhenNotConfigured(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            ['my_service' => []],
        );

        $closureVars = $this->getNewWorkerClosureVars();
        $this->assertSame('__invoke', $closureVars['method']);
    }

    public function testOnWorkerStartClosureCarriesExplicitMethod(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            ['my_service' => ['method' => 'customMethod']],
        );

        $closureVars = $this->getNewWorkerClosureVars();
        $this->assertSame('customMethod', $closureVars['method']);
    }

    public function testOnWorkerStartClosureCarriesEmptyMethodString(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            ['my_service' => ['method' => '']],
        );

        $closureVars = $this->getNewWorkerClosureVars();
        $this->assertSame('__invoke', $closureVars['method']);
    }

    public function testSupervisorWorkerUsesCorrectMethodResolutionPattern(): void
    {
        $sourceFile = \dirname(__DIR__, 2) . '/src/Worker/SupervisorWorker.php';
        $this->assertFileExists($sourceFile);

        $content = \file_get_contents($sourceFile);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            "empty(\$serviceConfig['method']) ? '__invoke' : \$serviceConfig['method']",
            $content,
            'Method resolution must default to __invoke when no method is configured',
        );
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
