<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\DependencyInjection;

use CrazyGoat\WorkermanBundle\DependencyInjection\WorkermanCompilerPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelInterface;

final class WorkermanCompilerPassTest extends TestCase
{
    private ContainerBuilder $container;
    private WorkermanCompilerPass $compilerPass;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->compilerPass = new WorkermanCompilerPass();
    }

    public function testImplementsCompilerPassInterface(): void
    {
        $this->assertInstanceOf(\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface::class, $this->compilerPass);
    }

    public function testRegistersServiceLocatorsWhenTaggedServicesExist(): void
    {
        $this->container->register('workerman.config_loader', \stdClass::class);

        $this->container->register('test.task', \stdClass::class)
            ->addTag('workerman.task');
        $this->container->register('test.process', \stdClass::class)
            ->addTag('workerman.process');

        $this->compilerPass->process($this->container);

        $this->assertTrue($this->container->has('workerman.task_locator'));
        $this->assertTrue($this->container->has('workerman.process_locator'));
        $this->assertTrue($this->container->has('workerman.reboot_strategy'));
        $this->assertTrue($this->container->has('workerman.response_converter'));
        $this->assertTrue($this->container->has('workerman.http_request_handler'));
        $this->assertTrue($this->container->has('workerman.task_handler'));
        $this->assertTrue($this->container->has('workerman.process_handler'));
    }

    public function testServiceLocatorsContainCorrectReferences(): void
    {
        $this->container->register('workerman.config_loader', \stdClass::class);

        $this->container->register('task.service', \stdClass::class)
            ->addTag('workerman.task');
        $this->container->register('process.service', \stdClass::class)
            ->addTag('workerman.process');

        $this->compilerPass->process($this->container);

        $taskLocator = $this->container->getDefinition('workerman.task_locator');
        $taskLocatorArgs = $taskLocator->getArguments();
        $this->assertArrayHasKey('task.service', $taskLocatorArgs[0]);

        $processLocator = $this->container->getDefinition('workerman.process_locator');
        $processLocatorArgs = $processLocator->getArguments();
        $this->assertArrayHasKey('process.service', $processLocatorArgs[0]);
    }

    public function testHandlesNoTaggedServices(): void
    {
        $this->container->register('workerman.config_loader', \stdClass::class);

        $this->compilerPass->process($this->container);

        $this->assertTrue($this->container->has('workerman.task_locator'));
        $this->assertTrue($this->container->has('workerman.process_locator'));
        $this->assertTrue($this->container->has('workerman.reboot_strategy'));
        $this->assertTrue($this->container->has('workerman.response_converter'));
    }

    public function testResponseConverterStrategiesAreSortedByPriority(): void
    {
        $this->container->register('workerman.config_loader', \stdClass::class);

        $this->container->register('strategy.low', \stdClass::class)
            ->addTag('workerman.response_converter.strategy', ['priority' => 0]);
        $this->container->register('strategy.high', \stdClass::class)
            ->addTag('workerman.response_converter.strategy', ['priority' => 100]);
        $this->container->register('strategy.medium', \stdClass::class)
            ->addTag('workerman.response_converter.strategy', ['priority' => 50]);

        $this->compilerPass->process($this->container);

        $responseConverter = $this->container->getDefinition('workerman.response_converter');
        $args = $responseConverter->getArguments();
        $references = $args[0];

        $this->assertCount(3, $references);
        $this->assertArrayHasKey('strategy.high', $references);
        $this->assertArrayHasKey('strategy.medium', $references);
        $this->assertArrayHasKey('strategy.low', $references);
        $this->assertInstanceOf(Reference::class, $references['strategy.high']);
        $this->assertInstanceOf(Reference::class, $references['strategy.medium']);
        $this->assertInstanceOf(Reference::class, $references['strategy.low']);
        $this->assertSame('strategy.high', (string) $references['strategy.high']);
        $this->assertSame('strategy.medium', (string) $references['strategy.medium']);
        $this->assertSame('strategy.low', (string) $references['strategy.low']);
    }

    public function testHttpRequestHandlerIsPublic(): void
    {
        $this->container->register('workerman.config_loader', \stdClass::class);

        $this->compilerPass->process($this->container);

        $httpRequestHandler = $this->container->getDefinition('workerman.http_request_handler');
        $this->assertTrue($httpRequestHandler->isPublic());
    }

    public function testRegistersSymfonyControllerService(): void
    {
        $this->container->register('workerman.config_loader', \stdClass::class);

        $this->compilerPass->process($this->container);

        $this->assertTrue($this->container->has('workerman.symfony_controller'));
    }

    public function testSymfonyControllerReceivesCorrectDependencies(): void
    {
        $this->container->register('workerman.config_loader', \stdClass::class);

        $this->compilerPass->process($this->container);

        $definition = $this->container->getDefinition('workerman.symfony_controller');
        $args = $definition->getArguments();

        $this->assertCount(4, $args);
        $this->assertInstanceOf(Reference::class, $args[0]);
        $this->assertSame(KernelInterface::class, (string) $args[0]);
        $this->assertInstanceOf(Reference::class, $args[1]);
        $this->assertSame('workerman.response_converter', (string) $args[1]);
        $this->assertNull($args[2]); // logger (optional)
        $this->assertSame('%workerman.trusted_hosts%', $args[3]);
    }

    public function testTaskAndProcessHandlersArePublic(): void
    {
        $this->container->register('workerman.config_loader', \stdClass::class);

        $this->container->register('task.service', \stdClass::class)
            ->addTag('workerman.task');
        $this->container->register('process.service', \stdClass::class)
            ->addTag('workerman.process');

        $this->compilerPass->process($this->container);

        $taskHandler = $this->container->getDefinition('workerman.task_handler');
        $processHandler = $this->container->getDefinition('workerman.process_handler');

        $this->assertTrue($taskHandler->isPublic());
        $this->assertTrue($processHandler->isPublic());
    }

    public function testReferenceMapReturnsCorrectStructure(): void
    {
        $this->container->register('workerman.config_loader', \stdClass::class);

        $this->container->register('service.a', \stdClass::class)
            ->addTag('workerman.task');
        $this->container->register('service.b', \stdClass::class)
            ->addTag('workerman.task');

        $this->compilerPass->process($this->container);

        $taskLocator = $this->container->getDefinition('workerman.task_locator');
        $args = $taskLocator->getArguments();
        $references = $args[0];

        $this->assertInstanceOf(Reference::class, $references['service.a']);
        $this->assertInstanceOf(Reference::class, $references['service.b']);
        $this->assertSame('service.a', (string) $references['service.a']);
        $this->assertSame('service.b', (string) $references['service.b']);
    }

    public function testReferenceMapUsesOnlyServiceIdsNotTagAttributes(): void
    {
        $this->container->register('workerman.config_loader', \stdClass::class);

        $this->container->register('service.one', \stdClass::class)
            ->addTag('workerman.task', ['priority' => 10, 'env' => 'prod']);
        $this->container->register('service.two', \stdClass::class)
            ->addTag('workerman.task', ['priority' => 20, 'env' => 'dev']);

        $this->compilerPass->process($this->container);

        $taskLocator = $this->container->getDefinition('workerman.task_locator');
        $args = $taskLocator->getArguments();
        $references = $args[0];

        $this->assertCount(2, $references);
        $this->assertArrayHasKey('service.one', $references);
        $this->assertArrayHasKey('service.two', $references);
        $this->assertSame('service.one', (string) $references['service.one']);
        $this->assertSame('service.two', (string) $references['service.two']);
    }

    public function testRebootStrategyReferenceMapUsesOnlyServiceIds(): void
    {
        $this->container->register('workerman.config_loader', \stdClass::class);

        $this->container->register('strategy.a', \stdClass::class)
            ->addTag('workerman.reboot_strategy');
        $this->container->register('strategy.b', \stdClass::class)
            ->addTag('workerman.reboot_strategy');

        $this->compilerPass->process($this->container);

        $rebootStrategy = $this->container->getDefinition('workerman.reboot_strategy');
        $args = $rebootStrategy->getArguments();
        $references = $args[0];

        $this->assertCount(2, $references);
        $this->assertArrayHasKey('strategy.a', $references);
        $this->assertArrayHasKey('strategy.b', $references);
    }

    public function testTasksAndProcessesAreSortedDeterministically(): void
    {
        $this->container->register('workerman.config_loader', \stdClass::class);

        $this->container->register('z.last', \stdClass::class)->addTag('workerman.task');
        $this->container->register('a.first', \stdClass::class)->addTag('workerman.task');
        $this->container->register('m.middle', \stdClass::class)->addTag('workerman.task');

        $this->container->register('p.process.z', \stdClass::class)->addTag('workerman.process');
        $this->container->register('p.process.a', \stdClass::class)->addTag('workerman.process');

        $this->compilerPass->process($this->container);

        $taskLocator = $this->container->getDefinition('workerman.task_locator');
        $taskReferences = array_keys($taskLocator->getArguments()[0]);
        $this->assertSame(['a.first', 'm.middle', 'z.last'], $taskReferences, 'Tasks must be sorted by service ID');

        $processLocator = $this->container->getDefinition('workerman.process_locator');
        $processReferences = array_keys($processLocator->getArguments()[0]);
        $this->assertSame(['p.process.a', 'p.process.z'], $processReferences, 'Processes must be sorted by service ID');
    }

    public function testRebootStrategiesAreSortedDeterministically(): void
    {
        $this->container->register('workerman.config_loader', \stdClass::class);

        $this->container->register('strategy.z', \stdClass::class)->addTag('workerman.reboot_strategy');
        $this->container->register('strategy.a', \stdClass::class)->addTag('workerman.reboot_strategy');

        $this->compilerPass->process($this->container);

        $rebootStrategy = $this->container->getDefinition('workerman.reboot_strategy');
        $references = array_keys($rebootStrategy->getArguments()[0]);
        $this->assertSame(['strategy.a', 'strategy.z'], $references, 'Reboot strategies must be sorted by service ID');
    }

    public function testResponseConverterStrategiesRetainPriorityOrder(): void
    {
        $this->container->register('workerman.config_loader', \stdClass::class);

        $this->container->register('strategy.low', \stdClass::class)
            ->addTag('workerman.response_converter.strategy', ['priority' => 0]);
        $this->container->register('strategy.high', \stdClass::class)
            ->addTag('workerman.response_converter.strategy', ['priority' => 100]);
        $this->container->register('strategy.medium', \stdClass::class)
            ->addTag('workerman.response_converter.strategy', ['priority' => 50]);
        // Register another low-priority strategy to verify stable sort behavior
        $this->container->register('strategy.low.another', \stdClass::class)
            ->addTag('workerman.response_converter.strategy', ['priority' => 0]);

        $this->compilerPass->process($this->container);

        $responseConverter = $this->container->getDefinition('workerman.response_converter');
        $references = array_keys($responseConverter->getArguments()[0]);

        // Expected: descending priority (100, 50, 0, 0). For equal priorities,
        // uasort preserves insertion order (low before low.another).
        $expected = ['strategy.high', 'strategy.medium', 'strategy.low', 'strategy.low.another'];
        $this->assertSame($expected, $references, 'Response converter strategies must be sorted by priority descending');
    }
}
