<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Worker;

use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Worker\SupervisorWorker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Workerman\Worker;

final class SupervisorWorkerTest extends TestCase
{
    private KernelFactory $kernelFactory;
    private array $initialWorkers;
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
        $this->assertArrayNotHasKey('name', $closureVars['serviceConfig']);
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

    public function testOnWorkerStartClosureHasDefaultMethodWhenNotConfigured(): void
    {
        new SupervisorWorker(
            $this->kernelFactory,
            null,
            null,
            ['my_service' => []],
        );

        $closureVars = $this->getNewWorkerClosureVars();
        $this->assertArrayNotHasKey('method', $closureVars['serviceConfig']);
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
        $this->assertSame('customMethod', $closureVars['serviceConfig']['method']);
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
        $this->assertSame('', $closureVars['serviceConfig']['method']);
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
        $closureRef = new \ReflectionFunction($this->getNewWorker()->onWorkerStart);

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
