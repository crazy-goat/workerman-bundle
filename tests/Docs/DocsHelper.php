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
use Symfony\Component\Config\Definition\VariableNode;
use Symfony\Component\Config\FileLocator;

/**
 * Shared helpers for the tests that keep the user docs true (issue #878, section 5).
 */
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
     * The user pages: README.md and every Markdown file directly in docs/.
     *
     * @return list<string> paths relative to the project root
     */
    public static function userPages(): array
    {
        $pages = ['README.md'];
        foreach (glob(self::rootDir() . '/docs/*.md') ?: [] as $file) {
            $pages[] = 'docs/' . basename($file);
        }

        return $pages;
    }

    /**
     * Build the config tree of the bundle.
     */
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
     * Every leaf of the config tree, keyed by its path (for example `reload_strategy.memory.gc_limit`).
     * A list of servers is written `servers[]`.
     *
     * @return array<string, VariableNode>
     */
    public static function configLeaves(): array
    {
        $leaves = [];
        self::collectLeaves(self::configTree(), '', $leaves);

        return $leaves;
    }

    /**
     * @param array<string, VariableNode> $leaves
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

        if ($node instanceof VariableNode) {
            $leaves[$path] = $node;
        }
    }

    /**
     * @param array<string, VariableNode> $leaves
     */
    private static function collectChildren(ArrayNode $node, string $path, array &$leaves): void
    {
        foreach ($node->getChildren() as $name => $child) {
            self::collectLeaves($child, $path === '' ? $name : $path . '.' . $name, $leaves);
        }
    }

    /**
     * The default of a leaf, as the strings a doc row must show.
     *
     * @return list<string>
     */
    public static function defaultTokens(VariableNode $node): array
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
     * Fenced YAML blocks of a page that hold a `workerman:` root, also inside `when@<env>:`.
     *
     * @return list<array<mixed>> the parsed `workerman` configs
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
     * @return list<string> the content of every ```yaml block
     */
    public static function yamlBlocks(string $markdown): array
    {
        preg_match_all('/^```yaml\s*\n(.*?)^```/ms', $markdown, $matches);

        return $matches[1];
    }
}
