<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\DependencyInjection;

use CrazyGoat\WorkermanBundle\Http\HttpRequestHandler;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\StackRebootStrategy;
use CrazyGoat\WorkermanBundle\Scheduler\TaskHandler;
use CrazyGoat\WorkermanBundle\Supervisor\ProcessHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class WorkermanCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $tasks = array_map(fn(array $a) => $a[0], $container->findTaggedServiceIds('workerman.task'));
        $processes = array_map(fn(array $a) => $a[0], $container->findTaggedServiceIds('workerman.process'));
        $rebootStrategies = array_map(fn(array $a) => $a[0], $container->findTaggedServiceIds('workerman.reboot_strategy'));
        $responseConverterStrategiesTagged = $container->findTaggedServiceIds('workerman.response_converter.strategy');
        uasort($responseConverterStrategiesTagged, fn(array $a, array $b): int => ($b[0]['priority'] ?? 0) <=> ($a[0]['priority'] ?? 0));
        $responseConverterStrategies = array_map(fn(array $a) => $a[0], $responseConverterStrategiesTagged);

        $container
            ->getDefinition('workerman.config_loader')
            ->addMethodCall('setProcessConfig', [$processes])
            ->addMethodCall('setSchedulerConfig', [$tasks]);

        $container
            ->register('workerman.task_locator', ServiceLocator::class)
            ->addTag('container.service_locator')
            ->setArguments([$this->referenceMap($tasks)]);

        $container
            ->register('workerman.process_locator', ServiceLocator::class)
            ->addTag('container.service_locator')
            ->setArguments([$this->referenceMap($processes)]);

        $container
            ->register('workerman.reboot_strategy', StackRebootStrategy::class)
            ->setArguments([$this->referenceMap($rebootStrategies)]);

        $container
            ->register('workerman.response_converter', ResponseConverter::class)
            ->setArguments([$this->referenceMap($responseConverterStrategies)]);

        $container
            ->register('workerman.http_request_handler', HttpRequestHandler::class)
            ->setPublic(true)
            ->setArguments([
                new Reference(KernelInterface::class),
                new Reference('workerman.reboot_strategy'),
                new Reference('workerman.response_converter'),
                [],
            ]);

        $container
            ->register('workerman.task_handler', TaskHandler::class)
            ->setPublic(true)
            ->setArguments([
                new Reference('workerman.task_locator'),
                new Reference(EventDispatcherInterface::class),
            ]);

        $container
            ->register('workerman.process_handler', ProcessHandler::class)
            ->setPublic(true)
            ->setArguments([
                new Reference('workerman.process_locator'),
                new Reference(EventDispatcherInterface::class),
            ]);
    }

    /**
     * Creates a Reference map from tagged services for ServiceLocator registration.
     *
     * @param array<string, array<string, mixed>> $taggedServices Output from findTaggedServiceIds()
     *
     * @return array<string, Reference> Service id => Reference mapping
     */
    private function referenceMap(array $taggedServices): array
    {
        $result = [];
        foreach (array_keys($taggedServices) as $id) {
            $result[$id] = new Reference($id);
        }
        return $result;
    }
}
