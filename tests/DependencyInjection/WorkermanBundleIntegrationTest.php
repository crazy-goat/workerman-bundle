<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\DependencyInjection;

use CrazyGoat\WorkermanBundle\CacheWarmupTimeoutConfig;
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

    protected function tearDown(): void
    {
        CacheWarmupTimeoutConfig::reset();
        unset($_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'], $_ENV['WORKERMAN_CACHE_WARMUP_TIMEOUT']);
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

    public function testLoadExtensionSetsCacheWarmupTimeoutHolder(): void
    {
        $this->extension->load([[
            'cache_warmup_timeout' => 45,
        ]], $this->container);

        self::assertSame(45, CacheWarmupTimeoutConfig::get());
    }

    public function testLoadExtensionDoesNotMutateServerSuperglobal(): void
    {
        $savedServer = $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] ?? null;
        unset($_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT']);

        $this->extension->load([[
            'cache_warmup_timeout' => 45,
        ]], $this->container);

        self::assertArrayNotHasKey(
            'WORKERMAN_CACHE_WARMUP_TIMEOUT',
            $_SERVER,
            'loadExtension must not write to $_SERVER',
        );

        $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = $savedServer;
    }

    public function testLoadExtensionRespectsServerEnvOverride(): void
    {
        $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = '90';

        $this->extension->load([[
            'cache_warmup_timeout' => 30,
        ]], $this->container);

        self::assertSame(90, CacheWarmupTimeoutConfig::get());
    }

    public function testLoadExtensionRespectsEnvEnvOverride(): void
    {
        unset($_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT']);
        $_ENV['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = '120';

        $this->extension->load([[
            'cache_warmup_timeout' => 30,
        ]], $this->container);

        self::assertSame(120, CacheWarmupTimeoutConfig::get());
    }

    public function testLoadExtensionEmptyEnvOverrideFallsBackToConfig(): void
    {
        $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = '';

        $this->extension->load([[
            'cache_warmup_timeout' => 30,
        ]], $this->container);

        self::assertSame(30, CacheWarmupTimeoutConfig::get());
    }

    public function testLoadExtensionRejectsNonPositiveEnvOverride(): void
    {
        $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = '0';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WORKERMAN_CACHE_WARMUP_TIMEOUT must be a positive integer');
        $this->extension->load([[
            'cache_warmup_timeout' => 30,
        ]], $this->container);
    }

    public function testLoadExtensionNonNumericEnvOverrideCoercesToZero(): void
    {
        $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = 'abc';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WORKERMAN_CACHE_WARMUP_TIMEOUT must be a positive integer');
        $this->extension->load([[
            'cache_warmup_timeout' => 30,
        ]], $this->container);
    }

    public function testLoadExtensionDefaultConfigValueSetsHolderTo30(): void
    {
        $this->extension->load([[]], $this->container);

        self::assertSame(30, CacheWarmupTimeoutConfig::get());
    }

    public function testLoadExtensionWhitespaceEnvOverrideIsTrimmed(): void
    {
        $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = ' 45 ';

        $this->extension->load([[
            'cache_warmup_timeout' => 30,
        ]], $this->container);

        self::assertSame(45, CacheWarmupTimeoutConfig::get());
    }

    public function testLoadExtensionFloatEnvOverrideIsTruncated(): void
    {
        $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = '3.7';

        $this->extension->load([[
            'cache_warmup_timeout' => 30,
        ]], $this->container);

        self::assertSame(3, CacheWarmupTimeoutConfig::get());
    }

    public function testLoadExtensionNegativeEnvOverrideIsRejected(): void
    {
        $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = '-5';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WORKERMAN_CACHE_WARMUP_TIMEOUT must be a positive integer');
        $this->extension->load([[
            'cache_warmup_timeout' => 30,
        ]], $this->container);
    }

    public function testLoadExtensionServerTakesPrecedenceOverEnv(): void
    {
        $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = '60';
        $_ENV['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = '90';

        $this->extension->load([[
            'cache_warmup_timeout' => 30,
        ]], $this->container);

        self::assertSame(60, CacheWarmupTimeoutConfig::get());
    }
}
