<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\DependencyInjection;

use CrazyGoat\WorkermanBundle\Attribute\AsProcess;
use CrazyGoat\WorkermanBundle\Attribute\AsTask;
use CrazyGoat\WorkermanBundle\Command\BuildBinCommand;
use CrazyGoat\WorkermanBundle\Command\BuildPathResolver;
use CrazyGoat\WorkermanBundle\Command\BuildPharCommand;
use CrazyGoat\WorkermanBundle\Command\WorkermanCommand;
use CrazyGoat\WorkermanBundle\ConfigLoader;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\BinaryFileResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\StreamedResponseStrategy;
use CrazyGoat\WorkermanBundle\Phar\BinaryComposer;
use CrazyGoat\WorkermanBundle\Phar\PharBuilder;
use CrazyGoat\WorkermanBundle\Phar\SfxDownloader;
use CrazyGoat\WorkermanBundle\Phar\SfxSourceResolver;
use CrazyGoat\WorkermanBundle\ProcessInspector;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\AlwaysRebootStrategy;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\ExceptionRebootStrategy;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\MaxJobsRebootStrategy;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\MemoryRebootStrategy;
use CrazyGoat\WorkermanBundle\Scheduler\TaskErrorListener;
use CrazyGoat\WorkermanBundle\ServerManager;
use CrazyGoat\WorkermanBundle\StatusFileReader;
use CrazyGoat\WorkermanBundle\Supervisor\ProcessErrorListener;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final readonly class ServicesConfigurator
{
    /**
     * @param array<string, mixed> $config
     */
    public function configure(array $config, ContainerBuilder $container): void
    {
        $this->configureParameters($config, $container);
        $this->configureConfigLoader($config, $container);
        $this->configureErrorListeners($container);
        $this->configureAutoConfiguration($container);
        $this->configureRebootStrategies($config, $container);
        $this->configureCoreServices($container);
        $this->configureBuildServices($container);
        $this->configureBuildCommands($container);
        $this->configureResponseStrategies($container);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureParameters(array $config, ContainerBuilder $container): void
    {
        $container->setParameter('workerman.response_chunk_size', $config['response_chunk_size']);
        $container->setParameter('workerman.cache_warmup_timeout', $config['cache_warmup_timeout']);
        $container->setParameter('workerman.trusted_hosts', $config['trusted_hosts']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureConfigLoader(array $config, ContainerBuilder $container): void
    {
        $container
            ->register('workerman.config_loader', ConfigLoader::class)
            ->setPublic(true)
            ->addMethodCall('setWorkermanConfig', [$config])
            ->addMethodCall('setBuildConfig', [$config['build']])
            ->addTag('kernel.cache_warmer')
            ->setArguments([
                $container->getParameter('kernel.project_dir'),
                $container->getParameter('kernel.cache_dir'),
                $container->getParameter('kernel.debug'),
            ])
        ;

        $container->setAlias(ConfigLoader::class, 'workerman.config_loader');
    }

    private function configureErrorListeners(ContainerBuilder $container): void
    {
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
    }

    private function configureAutoConfiguration(ContainerBuilder $container): void
    {
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
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureRebootStrategies(array $config, ContainerBuilder $container): void
    {
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
                    $config['reload_strategy']['memory']['gc_cooldown'],
                ])
            ;
        }
    }

    private function configureCoreServices(ContainerBuilder $container): void
    {
        $container
            ->register(ServerManager::class)
            ->setAutowired(true)
        ;

        $container
            ->register(ProcessInspector::class)
            ->setAutowired(true)
        ;

        $container
            ->register(StatusFileReader::class)
            ->setAutowired(true)
        ;

        $container
            ->register(WorkermanCommand::class)
            ->addTag('console.command')
            ->setAutowired(true)
        ;
    }

    private function configureBuildServices(ContainerBuilder $container): void
    {
        $container
            ->register('workerman.phar_builder', PharBuilder::class)
            ->setArguments([
                $container->getParameter('kernel.project_dir'),
                $container->getParameter('kernel.environment'),
            ])
        ;

        $container
            ->register('workerman.sfx_downloader', SfxDownloader::class)
        ;

        $container
            ->register('workerman.binary_composer', BinaryComposer::class)
        ;

        $container
            ->register(BuildPathResolver::class)
        ;

        $container
            ->register(SfxSourceResolver::class)
        ;
    }

    private function configureBuildCommands(ContainerBuilder $container): void
    {
        $container
            ->register(BuildPharCommand::class)
            ->addTag('console.command')
            ->setArguments([
                new Reference('workerman.config_loader'),
                new Reference('workerman.phar_builder'),
                new Reference(BuildPathResolver::class),
                $container->getParameter('kernel.project_dir'),
            ])
        ;

        $container
            ->register(BuildBinCommand::class)
            ->addTag('console.command')
            ->setArguments([
                new Reference('workerman.config_loader'),
                new Reference('workerman.phar_builder'),
                new Reference('workerman.sfx_downloader'),
                new Reference('workerman.binary_composer'),
                new Reference(BuildPathResolver::class),
                new Reference(SfxSourceResolver::class),
                $container->getParameter('kernel.project_dir'),
            ])
        ;
    }

    private function configureResponseStrategies(ContainerBuilder $container): void
    {
        $container
            ->register('workerman.binary_file_response_strategy', BinaryFileResponseStrategy::class)
            ->addTag('workerman.response_converter.strategy', ['priority' => 100])
        ;

        $container
            ->register('workerman.streamed_response_strategy', StreamedResponseStrategy::class)
            ->addTag('workerman.response_converter.strategy', ['priority' => 50])
            ->setArguments([
                $container->getParameter('workerman.response_chunk_size'),
            ])
        ;

        $container
            ->register('workerman.default_response_strategy', DefaultResponseStrategy::class)
            ->addTag('workerman.response_converter.strategy', ['priority' => 0])
            ->setArguments([
                $container->getParameter('workerman.response_chunk_size'),
            ])
        ;
    }
}
