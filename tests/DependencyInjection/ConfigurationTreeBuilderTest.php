<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\DependencyInjection;

use CrazyGoat\WorkermanBundle\DependencyInjection\ConfigurationTreeBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Loader\DefinitionFileLoader;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;

final class ConfigurationTreeBuilderTest extends TestCase
{
    public function testConfigureBuildsValidTree(): void
    {
        $configurator = $this->createDefinitionConfigurator();

        (new ConfigurationTreeBuilder())->configure($configurator);

        $root = $configurator->rootNode();
        self::assertInstanceOf(ArrayNodeDefinition::class, $root);
    }

    public function testConfiguredTreeProcessesFullConfig(): void
    {
        $configurator = $this->createDefinitionConfigurator();
        (new ConfigurationTreeBuilder())->configure($configurator);

        $root = $configurator->rootNode();
        self::assertInstanceOf(ArrayNodeDefinition::class, $root);

        $processor = new Processor();
        $node = $root->getNode(true);

        $config = $processor->process($node, [[
            'user' => 'www-data',
            'group' => 'www-data',
            'stop_timeout' => 5,
            'cache_warmup_timeout' => 60,
            'status_timeout' => 10,
            'pid_file' => '/tmp/workerman.pid',
            'log_file' => '/tmp/workerman.log',
            'stdout_file' => '/tmp/workerman.stdout.log',
            'servers' => [
                [
                    'name' => 'web',
                    'listen' => 'http://0.0.0.0:80',
                    'processes' => 4,
                ],
            ],
        ]]);

        self::assertSame('www-data', $config['user']);
        self::assertSame('www-data', $config['group']);
        self::assertSame(5, $config['stop_timeout']);
        self::assertSame(60, $config['cache_warmup_timeout']);
        self::assertSame(10, $config['status_timeout']);
        self::assertSame('/tmp/workerman.pid', $config['pid_file']);
        self::assertCount(1, $config['servers']);
        self::assertSame('web', $config['servers'][0]['name']);
    }

    public function testConfiguredTreeAppliesDefaults(): void
    {
        $configurator = $this->createDefinitionConfigurator();
        (new ConfigurationTreeBuilder())->configure($configurator);

        $root = $configurator->rootNode();
        self::assertInstanceOf(ArrayNodeDefinition::class, $root);

        $processor = new Processor();
        $node = $root->getNode(true);

        $config = $processor->process($node, [[]]);

        self::assertSame(2, $config['stop_timeout']);
        self::assertSame(30, $config['cache_warmup_timeout']);
        self::assertSame(5, $config['status_timeout']);
        self::assertSame(120, $config['connection_timeout']);
        self::assertSame(30, $config['keepalive_timeout']);
        self::assertFalse($config['reload_strategy']['max_requests']['active']);
        self::assertTrue($config['reload_strategy']['exception']['active']);
        self::assertFalse($config['reload_strategy']['file_monitor']['active']);
        self::assertFalse($config['reload_strategy']['always']['active']);
        self::assertFalse($config['reload_strategy']['memory']['active']);
    }

    public function testConfiguredTreeParsesConnectionTimeouts(): void
    {
        $configurator = $this->createDefinitionConfigurator();
        (new ConfigurationTreeBuilder())->configure($configurator);

        $root = $configurator->rootNode();
        self::assertInstanceOf(ArrayNodeDefinition::class, $root);

        $processor = new Processor();
        $node = $root->getNode(true);

        $config = $processor->process($node, [[
            'connection_timeout' => 60,
            'keepalive_timeout' => 15,
        ]]);

        self::assertSame(60, $config['connection_timeout']);
        self::assertSame(15, $config['keepalive_timeout']);
    }

    public function testConfiguredTreeAcceptsZeroTimeouts(): void
    {
        $configurator = $this->createDefinitionConfigurator();
        (new ConfigurationTreeBuilder())->configure($configurator);

        $root = $configurator->rootNode();
        self::assertInstanceOf(ArrayNodeDefinition::class, $root);

        $processor = new Processor();
        $node = $root->getNode(true);

        $config = $processor->process($node, [[
            'connection_timeout' => 0,
            'keepalive_timeout' => 0,
        ]]);

        self::assertSame(0, $config['connection_timeout']);
        self::assertSame(0, $config['keepalive_timeout']);
    }

    /**
     * @return iterable<string, array{array<string, int>}>
     */
    public static function provideNegativeTimeoutOverrides(): iterable
    {
        yield 'connection_timeout' => [['connection_timeout' => -1]];
        yield 'keepalive_timeout' => [['keepalive_timeout' => -1]];
    }

    /**
     * One field at a time: Symfony's Processor throws on the first invalid
     * child, so a combined payload would only exercise the first-declared
     * node's bound (F-4, review round 2).
     *
     * @dataProvider provideNegativeTimeoutOverrides
     *
     * @param array<string, int> $override
     */
    public function testConfiguredTreeRejectsNegativeTimeouts(array $override): void
    {
        $configurator = $this->createDefinitionConfigurator();
        (new ConfigurationTreeBuilder())->configure($configurator);

        $root = $configurator->rootNode();
        self::assertInstanceOf(ArrayNodeDefinition::class, $root);

        $processor = new Processor();
        $node = $root->getNode(true);

        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $processor->process($node, [[$override]]);
    }

    public function testConfiguredTreeParsesServerBodySizeCap(): void
    {
        $configurator = $this->createDefinitionConfigurator();
        (new ConfigurationTreeBuilder())->configure($configurator);

        $root = $configurator->rootNode();
        self::assertInstanceOf(ArrayNodeDefinition::class, $root);

        $processor = new Processor();
        $node = $root->getNode(true);

        $config = $processor->process($node, [[
            'servers' => [
                [
                    'name' => 'api',
                    'listen' => 'http://0.0.0.0:80',
                    'body_size_cap' => 1048576,
                ],
                [
                    'name' => 'upload',
                    'listen' => 'http://0.0.0.0:8080',
                ],
            ],
        ]]);

        self::assertCount(2, $config['servers']);
        self::assertSame(1048576, $config['servers'][0]['body_size_cap']);
        self::assertNull($config['servers'][1]['body_size_cap']);
    }

    public function testConfiguredTreeValidatesRequiredServerName(): void
    {
        $configurator = $this->createDefinitionConfigurator();
        (new ConfigurationTreeBuilder())->configure($configurator);

        $root = $configurator->rootNode();
        self::assertInstanceOf(ArrayNodeDefinition::class, $root);

        $processor = new Processor();
        $node = $root->getNode(true);

        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $processor->process($node, [[
            'servers' => [
                [
                    'listen' => 'http://0.0.0.0:80',
                ],
            ],
        ]]);
    }

    public function testMemoryNodeDoesNotSetDefaultForActive(): void
    {
        $configurator = $this->createDefinitionConfigurator();
        (new ConfigurationTreeBuilder())->configure($configurator);

        $root = $configurator->rootNode();
        self::assertInstanceOf(ArrayNodeDefinition::class, $root);

        $processor = new Processor();
        $node = $root->getNode(true);

        $config = $processor->process($node, [[]]);

        self::assertFalse($config['reload_strategy']['memory']['active']);
        self::assertSame(134_217_728, $config['reload_strategy']['memory']['limit']);
        self::assertSame(100_663_296, $config['reload_strategy']['memory']['gc_limit']);
        self::assertSame(60, $config['reload_strategy']['memory']['gc_cooldown']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideDeprecatedNodes(): iterable
    {
        yield 'serve_files' => [['serve_files' => true]];
        yield 'root_dir' => [['root_dir' => '/tmp']];
        yield 'static_files' => [['static_files' => ['allowed_extensions' => ['png']]]];
    }

    /**
     * The deprecated serve_files/root_dir/static_files nodes must still emit a
     * Symfony Config deprecation, and the message must state the removal
     * version (1.0) so users can plan the upgrade (issue #595).
     *
     * @dataProvider provideDeprecatedNodes
     *
     * @param array<string, mixed> $override
     */
    public function testConfiguredTreeDeprecatesLegacyStaticFileNodes(array $override): void
    {
        $configurator = $this->createDefinitionConfigurator();
        (new ConfigurationTreeBuilder())->configure($configurator);

        $root = $configurator->rootNode();
        self::assertInstanceOf(ArrayNodeDefinition::class, $root);

        $processor = new Processor();
        $node = $root->getNode(true);

        $deprecationMessage = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$deprecationMessage): bool {
            if ($errno === \E_USER_DEPRECATED) {
                $deprecationMessage = $errstr;

                return true;
            }

            return false;
        });

        try {
            $processor->process($node, [[
                'servers' => [
                    [
                        'name' => 'web',
                        'listen' => 'http://0.0.0.0:80',
                    ] + $override,
                ],
            ]]);
        } finally {
            restore_error_handler();
        }

        self::assertNotNull($deprecationMessage, 'Expected a config deprecation for the deprecated node');
        self::assertStringContainsString('Will be removed in 1.0', $deprecationMessage);
    }

    private function createDefinitionConfigurator(): DefinitionConfigurator
    {
        $treeBuilder = new TreeBuilder('workerman');
        $fileLocator = new FileLocator();
        $loader = new DefinitionFileLoader($treeBuilder, $fileLocator);

        return new DefinitionConfigurator($treeBuilder, $loader, __FILE__, __FILE__);
    }
}
