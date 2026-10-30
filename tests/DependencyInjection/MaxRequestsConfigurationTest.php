<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\DependencyInjection;

use CrazyGoat\WorkermanBundle\DependencyInjection\ConfigurationTreeBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Loader\DefinitionFileLoader;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;

final class MaxRequestsConfigurationTest extends TestCase
{
    /**
     * One field at a time: Symfony's Processor throws on the first invalid
     * child, so a combined payload would only exercise the first-declared
     * node's bound.
     *
     * @return iterable<string, array{array<string, int>}>
     */
    public static function provideInvalidMaxRequestsOverrides(): iterable
    {
        yield 'requests zero' => [['requests' => 0]];
        yield 'requests negative' => [['requests' => -5]];
        yield 'dispersion negative' => [['dispersion' => -1]];
        yield 'dispersion above 100' => [['dispersion' => 101]];
    }

    /**
     * @dataProvider provideInvalidMaxRequestsOverrides
     *
     * @param array<string, int> $override
     */
    public function testConfiguredTreeRejectsOutOfRangeMaxRequestsValues(array $override): void
    {
        $configurator = $this->createDefinitionConfigurator();
        (new ConfigurationTreeBuilder())->configure($configurator);

        $root = $configurator->rootNode();
        self::assertInstanceOf(ArrayNodeDefinition::class, $root);

        $processor = new Processor();
        $node = $root->getNode(true);

        $this->expectException(InvalidConfigurationException::class);

        $processor->process($node, [[
            'reload_strategy' => [
                'max_requests' => $override,
            ],
        ]]);
    }

    public function testConfiguredTreeAcceptsMaxRequestsBoundaries(): void
    {
        $configurator = $this->createDefinitionConfigurator();
        (new ConfigurationTreeBuilder())->configure($configurator);

        $root = $configurator->rootNode();
        self::assertInstanceOf(ArrayNodeDefinition::class, $root);

        $processor = new Processor();
        $node = $root->getNode(true);

        // requests 1 is the smallest supported value; dispersion 100 is the
        // largest and derives a minimum of zero (1 - 100% of 1 = 0).
        $config = $processor->process($node, [[
            'reload_strategy' => [
                'max_requests' => [
                    'active' => true,
                    'requests' => 1,
                    'dispersion' => 100,
                ],
            ],
        ]]);

        self::assertSame(1, $config['reload_strategy']['max_requests']['requests']);
        self::assertSame(100, $config['reload_strategy']['max_requests']['dispersion']);
    }

    public function testConfiguredTreeAcceptsZeroDispersion(): void
    {
        $configurator = $this->createDefinitionConfigurator();
        (new ConfigurationTreeBuilder())->configure($configurator);

        $root = $configurator->rootNode();
        self::assertInstanceOf(ArrayNodeDefinition::class, $root);

        $processor = new Processor();
        $node = $root->getNode(true);

        $config = $processor->process($node, [[
            'reload_strategy' => [
                'max_requests' => [
                    'requests' => 1000,
                    'dispersion' => 0,
                ],
            ],
        ]]);

        self::assertSame(1000, $config['reload_strategy']['max_requests']['requests']);
        self::assertSame(0, $config['reload_strategy']['max_requests']['dispersion']);
    }

    /**
     * The config trees built here create cyclic node references. Left in the
     * GC root buffer they perturb order-dependent GC assumptions elsewhere in
     * the suite (RebootStrategyTest's gc_collect_cycles count), so drain them.
     */
    protected function tearDown(): void
    {
        gc_collect_cycles();
    }

    private function createDefinitionConfigurator(): DefinitionConfigurator
    {
        $treeBuilder = new TreeBuilder('workerman');
        $fileLocator = new FileLocator();
        $loader = new DefinitionFileLoader($treeBuilder, $fileLocator);

        return new DefinitionConfigurator($treeBuilder, $loader, __FILE__, __FILE__);
    }
}
