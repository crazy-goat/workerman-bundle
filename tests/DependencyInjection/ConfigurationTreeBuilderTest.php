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
        self::assertFalse($config['reload_strategy']['max_requests']['active']);
        self::assertTrue($config['reload_strategy']['exception']['active']);
        self::assertFalse($config['reload_strategy']['file_monitor']['active']);
        self::assertFalse($config['reload_strategy']['always']['active']);
        self::assertFalse($config['reload_strategy']['memory']['active']);
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
    }

    private function createDefinitionConfigurator(): DefinitionConfigurator
    {
        $treeBuilder = new TreeBuilder('workerman');
        $fileLocator = new FileLocator();
        $loader = new DefinitionFileLoader($treeBuilder, $fileLocator);

        return new DefinitionConfigurator($treeBuilder, $loader, __FILE__, __FILE__);
    }
}
