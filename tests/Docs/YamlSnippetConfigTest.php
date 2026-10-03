<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * Every YAML example with a `workerman:` root in the user docs must pass the config tree (issue #878, section 5).
 *
 * @coversNothing
 */
final class YamlSnippetConfigTest extends TestCase
{
    public static function tearDownAfterClass(): void
    {
        // Leave the garbage collector as it was: RebootStrategyTest counts collected cycles.
        gc_collect_cycles();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function snippetProvider(): iterable
    {
        foreach (DocsHelper::userPages() as $page) {
            foreach (DocsHelper::yamlBlocks(DocsHelper::read($page)) as $index => $yaml) {
                if (!preg_match('/^\s*workerman:/m', $yaml)) {
                    continue;
                }

                yield sprintf('%s block %d', $page, $index + 1) => [$page, $yaml];
            }
        }
    }

    #[DataProvider('snippetProvider')]
    public function testSnippetPassesTheConfigTree(string $page, string $yaml): void
    {
        $configs = DocsHelper::workermanConfigsFromYaml($yaml);
        self::assertNotSame([], $configs, sprintf('A YAML block in %s has no workerman config.', $page));

        foreach ($configs as $config) {
            $processed = (new Processor())->process(DocsHelper::configTree(), [$config]);
            self::assertArrayHasKey('servers', $processed);
        }
    }

    public function testTheScanFindsSnippets(): void
    {
        self::assertNotSame([], iterator_to_array(self::snippetProvider()));
    }
}
