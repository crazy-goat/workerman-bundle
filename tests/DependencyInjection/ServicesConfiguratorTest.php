<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\DependencyInjection;

use CrazyGoat\WorkermanBundle\Command\WorkermanCommand;
use CrazyGoat\WorkermanBundle\ConfigLoader;
use CrazyGoat\WorkermanBundle\DependencyInjection\ServicesConfigurator;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\AlwaysRebootStrategy;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\ExceptionRebootStrategy;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\MaxJobsRebootStrategy;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\MemoryRebootStrategy;
use CrazyGoat\WorkermanBundle\Scheduler\TaskErrorListener;
use CrazyGoat\WorkermanBundle\ServerManager;
use CrazyGoat\WorkermanBundle\Supervisor\ProcessErrorListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ServicesConfiguratorTest extends TestCase
{
    private ContainerBuilder $container;
    private ServicesConfigurator $configurator;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->container->setParameter('kernel.project_dir', '/tmp/test');
        $this->container->setParameter('kernel.cache_dir', '/tmp/test/cache');
        $this->container->setParameter('kernel.debug', false);
        $this->container->setParameter('kernel.environment', 'test');
        $this->configurator = new ServicesConfigurator();
    }

    public function testConfigureSetsParameters(): void
    {
        $this->configurator->configure($this->getDefaultConfig(), $this->container);

        self::assertSame(2048, $this->container->getParameter('workerman.response_chunk_size'));
        self::assertSame(30, $this->container->getParameter('workerman.cache_warmup_timeout'));
    }

    public function testConfigureRegistersConfigLoader(): void
    {
        $this->configurator->configure($this->getDefaultConfig(), $this->container);

        self::assertTrue($this->container->hasDefinition('workerman.config_loader'));
        $definition = $this->container->getDefinition('workerman.config_loader');
        self::assertSame(ConfigLoader::class, $definition->getClass());
        self::assertTrue($definition->hasTag('kernel.cache_warmer'));
    }

    public function testConfigureRegistersErrorListeners(): void
    {
        $this->configurator->configure($this->getDefaultConfig(), $this->container);

        self::assertTrue($this->container->hasDefinition('workerman.task_error_listener'));
        self::assertSame(TaskErrorListener::class, $this->container->getDefinition('workerman.task_error_listener')->getClass());

        self::assertTrue($this->container->hasDefinition('workerman.process_error_listener'));
        self::assertSame(ProcessErrorListener::class, $this->container->getDefinition('workerman.process_error_listener')->getClass());
    }

    public function testConfigureRegistersServerManagerAndCommand(): void
    {
        $this->configurator->configure($this->getDefaultConfig(), $this->container);

        self::assertTrue($this->container->hasDefinition(ServerManager::class));
        self::assertTrue($this->container->getDefinition(ServerManager::class)->isAutowired());

        self::assertTrue($this->container->hasDefinition(WorkermanCommand::class));
        self::assertTrue($this->container->getDefinition(WorkermanCommand::class)->hasTag('console.command'));
    }

    public function testConfigureRegistersResponseConverterStrategies(): void
    {
        $this->configurator->configure($this->getDefaultConfig(), $this->container);

        self::assertTrue($this->container->hasDefinition('workerman.binary_file_response_strategy'));
        self::assertTrue($this->container->hasDefinition('workerman.streamed_response_strategy'));
        self::assertTrue($this->container->hasDefinition('workerman.default_response_strategy'));
    }

    public function testConfigureEnablesAlwaysRebootStrategy(): void
    {
        $config = $this->getDefaultConfig();
        $config['reload_strategy']['always']['active'] = true;

        $this->configurator->configure($config, $this->container);

        self::assertTrue($this->container->hasDefinition('workerman.always_reboot_strategy'));
        self::assertSame(AlwaysRebootStrategy::class, $this->container->getDefinition('workerman.always_reboot_strategy')->getClass());
    }

    public function testConfigureDisablesAlwaysRebootStrategyByDefault(): void
    {
        $this->configurator->configure($this->getDefaultConfig(), $this->container);

        self::assertFalse($this->container->hasDefinition('workerman.always_reboot_strategy'));
    }

    public function testConfigureEnablesMaxRequestsRebootStrategy(): void
    {
        $config = $this->getDefaultConfig();
        $config['reload_strategy']['max_requests']['active'] = true;

        $this->configurator->configure($config, $this->container);

        self::assertTrue($this->container->hasDefinition('workerman.max_requests_reboot_strategy'));
        $definition = $this->container->getDefinition('workerman.max_requests_reboot_strategy');
        self::assertSame(MaxJobsRebootStrategy::class, $definition->getClass());
        self::assertSame([1000, 20], $definition->getArguments());
    }

    public function testConfigureEnablesExceptionRebootStrategyByDefault(): void
    {
        $this->configurator->configure($this->getDefaultConfig(), $this->container);

        self::assertTrue($this->container->hasDefinition('workerman.exception_reboot_strategy'));
        self::assertSame(ExceptionRebootStrategy::class, $this->container->getDefinition('workerman.exception_reboot_strategy')->getClass());
    }

    public function testConfigureEnablesMemoryRebootStrategy(): void
    {
        $config = $this->getDefaultConfig();
        $config['reload_strategy']['memory']['active'] = true;

        $this->configurator->configure($config, $this->container);

        self::assertTrue($this->container->hasDefinition('workerman.memory_reboot_strategy'));
        $definition = $this->container->getDefinition('workerman.memory_reboot_strategy');
        self::assertSame(MemoryRebootStrategy::class, $definition->getClass());
        self::assertSame([134_217_728, 100_663_296], $definition->getArguments());
    }

    /**
     * @return array<string, mixed>
     */
    private function getDefaultConfig(): array
    {
        return [
            'runtime_dir' => '%kernel.project_dir%',
            'user' => null,
            'group' => null,
            'stop_timeout' => 2,
            'cache_warmup_timeout' => 30,
            'status_timeout' => 5,
            'pid_file' => '%kernel.project_dir%/var/run/workerman.pid',
            'log_file' => '%kernel.project_dir%/var/log/workerman.log',
            'stdout_file' => '%kernel.project_dir%/var/log/workerman.stdout.log',
            'max_package_size' => 10 * 1024 * 1024,
            'response_chunk_size' => 2048,
            'servers' => [],
            'reload_strategy' => [
                'exception' => [
                    'active' => true,
                    'allowed_exceptions' => [
                        \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface::class,
                        'Symfony\Component\Serializer\Exception\ExceptionInterface',
                    ],
                ],
                'max_requests' => [
                    'active' => false,
                    'requests' => 1000,
                    'dispersion' => 20,
                ],
                'file_monitor' => [
                    'active' => false,
                ],
                'always' => [
                    'active' => false,
                ],
                'memory' => [
                    'active' => false,
                    'limit' => 134_217_728,
                    'gc_limit' => 100_663_296,
                ],
            ],
            'build' => [
                'build_dir' => '%kernel.project_dir%/build',
                'kernel_class' => 'App\\Kernel',
                'phar_filename' => 'app.phar',
                'bin_filename' => 'app.bin',
                'bin_php_version' => null,
                'sfx' => [
                    'url' => null,
                    'file' => null,
                ],
                'exclude_patterns' => [],
                'exclude_files' => [],
                'custom_ini' => null,
            ],
        ];
    }
}
