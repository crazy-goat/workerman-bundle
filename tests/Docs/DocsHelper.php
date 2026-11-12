<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Docs;

use CrazyGoat\WorkermanBundle\DependencyInjection\ConfigurationTreeBuilder;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Loader\DefinitionFileLoader;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\PrototypedArrayNode;
use Symfony\Component\Config\FileLocator;

final class DocsHelper
{
    public static function rootDir(): string
    {
        $root = realpath(__DIR__ . '/../..');
        if ($root === false) {
            throw new \RuntimeException('Cannot determine the project root.');
        }

        return $root;
    }

    public static function read(string $relativePath): string
    {
        $contents = file_get_contents(self::rootDir() . '/' . $relativePath);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Cannot read %s.', $relativePath));
        }

        return $contents;
    }

    /**
     * @return list<string>
     */
    public static function userPages(): array
    {
        $pages = ['README.md'];
        foreach (glob(self::rootDir() . '/docs/*.md') ?: [] as $file) {
            $pages[] = 'docs/' . basename($file);
        }

        return $pages;
    }

    public static function configTree(): ArrayNode
    {
        $treeBuilder = new TreeBuilder('workerman');
        $loader = new DefinitionFileLoader($treeBuilder, new FileLocator());
        (new ConfigurationTreeBuilder())->configure(new DefinitionConfigurator($treeBuilder, $loader, __FILE__, __FILE__));

        $tree = $treeBuilder->buildTree();
        if (!$tree instanceof ArrayNode) {
            throw new \LogicException('The config root must be an array node.');
        }

        return $tree;
    }

    /**
     * @return array<string, NodeInterface>
     */
    public static function configLeaves(): array
    {
        $leaves = [];
        self::collectLeaves(self::configTree(), '', $leaves);

        return $leaves;
    }

    /**
     * @param array<string, NodeInterface> $leaves
     */
    private static function collectLeaves(NodeInterface $node, string $path, array &$leaves): void
    {
        if ($node instanceof PrototypedArrayNode) {
            $prototype = $node->getPrototype();
            if ($prototype instanceof ArrayNode) {
                self::collectChildren($prototype, $path . '[]', $leaves);

                return;
            }
        } elseif ($node instanceof ArrayNode) {
            self::collectChildren($node, $path, $leaves);

            return;
        }

        $leaves[$path] = $node;
    }

    /**
     * @param array<string, NodeInterface> $leaves
     */
    private static function collectChildren(ArrayNode $node, string $path, array &$leaves): void
    {
        foreach ($node->getChildren() as $name => $child) {
            self::collectLeaves($child, $path === '' ? $name : $path . '.' . $name, $leaves);
        }
    }

    /**
     * @return list<string>
     */
    public static function defaultTokens(NodeInterface $node): array
    {
        if (!$node->hasDefaultValue()) {
            return [];
        }

        $default = $node->getDefaultValue();

        return match (true) {
            $default === null => ['null'],
            is_bool($default) => [$default ? 'true' : 'false'],
            is_array($default) && $default === [] => ['[]'],
            is_array($default) => array_values(array_map(strval(...), $default)),
            default => [(string) $default],
        };
    }

    /**
     * @return list<array<mixed>>
     */
    public static function workermanConfigsFromYaml(string $yaml): array
    {
        $parsed = \Symfony\Component\Yaml\Yaml::parse($yaml);
        if (!is_array($parsed)) {
            return [];
        }

        $configs = [];
        foreach ($parsed as $key => $value) {
            if ($key === 'workerman' && is_array($value)) {
                $configs[] = $value;
            }
            if (is_string($key) && str_starts_with($key, 'when@') && is_array($value) && is_array($value['workerman'] ?? null)) {
                $configs[] = $value['workerman'];
            }
        }

        return $configs;
    }

    /**
     * @return list<string>
     */
    public static function yamlBlocks(string $markdown): array
    {
        preg_match_all('/^```yaml\s*\n(.*?)^```/ms', $markdown, $matches);

        return $matches[1];
    }
}
