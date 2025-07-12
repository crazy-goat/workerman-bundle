<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\config;

use CrazyGoat\WorkermanBundle\Http\HttpRequestHandler;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\StackRebootStrategy;
use CrazyGoat\WorkermanBundle\Scheduler\TaskHandler;
use CrazyGoat\WorkermanBundle\Supervisor\ProcessHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

return new class implements CompilerPassInterface {
    public function process(ContainerBuilder $container): void
    {
        $tasks = array_map(fn(array $a) => $a[0], $container->findTaggedServiceIds('workerman.task'));
        $processes = array_map(fn(array $a) => $a[0], $container->findTaggedServiceIds('workerman.process'));
        $rebootStrategies = array_map(fn(array $a) => $a[0], $container->findTaggedServiceIds('workerman.reboot_strategy'));

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
            ->register('workerman.http_request_handler', HttpRequestHandler::class)
            ->setPublic(true)
            ->setArguments([
                new Reference(KernelInterface::class),
                new Reference('workerman.reboot_strategy'),
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
     * @param string[] $taggedServices
     *
     * @return Reference[]
     */
    private function referenceMap(array $taggedServices): array
    {
        $result = [];
        foreach (array_keys($taggedServices) as $id) {
            $result[$id] = new Reference($id);
        }
        return $result;
    }
};
