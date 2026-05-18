<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\DependencyInjection;

use CrazyGoat\WorkermanBundle\WorkermanBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class WorkermanBundleIntegrationTest extends TestCase
{
    private ContainerBuilder $container;
    private WorkermanBundle $bundle;
    private ExtensionInterface $extension;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder(new ParameterBag([
            'kernel.project_dir' => sys_get_temp_dir(),
            'kernel.cache_dir' => sys_get_temp_dir() . '/cache',
            'kernel.build_dir' => sys_get_temp_dir() . '/cache',
            'kernel.debug' => false,
            'kernel.environment' => 'test',
            'kernel.bundles' => ['WorkermanBundle' => WorkermanBundle::class],
            'kernel.bundles_metadata' => [],
            'kernel.charset' => 'UTF-8',
            'kernel.container_class' => 'test',
        ]));

        $this->bundle = new WorkermanBundle();
        $extension = $this->bundle->getContainerExtension();
        self::assertNotNull($extension);
        $this->extension = $extension;
    }

    public function testBundleSetsParameters(): void
    {
        $this->extension->load([[]], $this->container);

        self::assertSame(2048, $this->container->getParameter('workerman.response_chunk_size'));
        self::assertSame(30, $this->container->getParameter('workerman.cache_warmup_timeout'));
    }

    public function testBundleConfiguresResponseChunkSize(): void
    {
        $this->extension->load([[
            'response_chunk_size' => 4096,
        ]], $this->container);

        self::assertSame(4096, $this->container->getParameter('workerman.response_chunk_size'));
    }

    public function testBundleRegistersConfigLoader(): void
    {
        $this->extension->load([[]], $this->container);

        self::assertTrue($this->container->hasDefinition('workerman.config_loader'));
        self::assertTrue($this->container->getDefinition('workerman.config_loader')->hasTag('kernel.cache_warmer'));
    }

    public function testBundleRegistersErrorListeners(): void
    {
        $this->extension->load([[]], $this->container);

        self::assertTrue($this->container->hasDefinition('workerman.task_error_listener'));
        self::assertTrue($this->container->hasDefinition('workerman.process_error_listener'));
    }

    public function testBundleWithFullReloadStrategy(): void
    {
        $this->extension->load([[
            'reload_strategy' => [
                'always' => ['active' => true],
                'max_requests' => ['active' => true, 'requests' => 500, 'dispersion' => 10],
                'memory' => ['active' => true, 'limit' => 64_000_000, 'gc_limit' => 32_000_000],
            ],
        ]], $this->container);

        self::assertTrue($this->container->hasDefinition('workerman.always_reboot_strategy'));
        self::assertTrue($this->container->hasDefinition('workerman.max_requests_reboot_strategy'));
        self::assertTrue($this->container->hasDefinition('workerman.exception_reboot_strategy'));
        self::assertTrue($this->container->hasDefinition('workerman.memory_reboot_strategy'));
    }
}
