<?php

declare(strict_types=1);

use CrazyGoat\WorkermanBundle\Attribute\AsProcess;
use CrazyGoat\WorkermanBundle\Attribute\AsTask;
use CrazyGoat\WorkermanBundle\ConfigLoader;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\AlwaysRebootStrategy;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\ExceptionRebootStrategy;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\MaxJobsRebootStrategy;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\MemoryRebootStrategy;
use CrazyGoat\WorkermanBundle\Scheduler\TaskErrorListener;
use CrazyGoat\WorkermanBundle\Supervisor\ProcessErrorListener;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

return static function (array $config, ContainerBuilder $container): void {
    $container
        ->setParameter('workerman.response_chunk_size', $config['response_chunk_size'])
    ;

    $container
        ->register('workerman.config_loader', ConfigLoader::class)
        ->addMethodCall('setWorkermanConfig', [$config])
        ->addTag('kernel.cache_warmer')
        ->setArguments([
            $container->getParameter('kernel.project_dir'),
            $container->getParameter('kernel.cache_dir'),
            $container->getParameter('kernel.debug'),
        ])
    ;

    $container
        ->register('workerman.task_error_listener', TaskErrorListener::class)
        ->addTag('kernel.event_subscriber')
        ->addTag('monolog.logger', ['channel' => 'task'])
        ->setArguments([
            new Reference('logger'),
        ])
    ;

    $container
        ->register('workerman.process_error_listener', ProcessErrorListener::class)
        ->addTag('kernel.event_subscriber')
        ->addTag('monolog.logger', ['channel' => 'process'])
        ->setArguments([
            new Reference('logger'),
        ])
    ;

    $container->registerAttributeForAutoconfiguration(AsProcess::class, static function (ChildDefinition $definition, AsProcess $attribute): void {
        $definition->addTag('workerman.process', [
            'name' => $attribute->name,
            'processes' => $attribute->processes,
            'method' => $attribute->method,
        ]);
    });

    $container->registerAttributeForAutoconfiguration(AsTask::class, static function (ChildDefinition $definition, AsTask $attribute): void {
        $definition->addTag('workerman.task', [
            'name' => $attribute->name,
            'schedule' => $attribute->schedule,
            'method' => $attribute->method,
            'jitter' => $attribute->jitter,
        ]);
    });

    if ($config['reload_strategy']['always']['active']) {
        $container
            ->register('workerman.always_reboot_strategy', AlwaysRebootStrategy::class)
            ->addTag('workerman.reboot_strategy')
        ;
    }

    if ($config['reload_strategy']['max_requests']['active']) {
        $container
            ->register('workerman.max_requests_reboot_strategy', MaxJobsRebootStrategy::class)
            ->addTag('workerman.reboot_strategy')
            ->setArguments([
                $config['reload_strategy']['max_requests']['requests'],
                $config['reload_strategy']['max_requests']['dispersion'],
            ])
        ;
    }

    if ($config['reload_strategy']['exception']['active']) {
        $container
            ->register('workerman.exception_reboot_strategy', ExceptionRebootStrategy::class)
            ->addTag('workerman.reboot_strategy')
            ->addTag('kernel.event_listener', [
                'event' => 'kernel.exception',
                'priority' => -100,
                'method' => 'onException',
            ])
            ->setArguments([
                $config['reload_strategy']['exception']['allowed_exceptions'],
            ])
        ;
    }

    if ($config['reload_strategy']['memory']['active'] === true) {
        $container
            ->register('workerman.memory_reboot_strategy', MemoryRebootStrategy::class)
            ->addTag('workerman.reboot_strategy')
            ->setArguments([
                $config['reload_strategy']['memory']['limit'],
                $config['reload_strategy']['memory']['gc_limit'],
            ])
        ;
    }
};
