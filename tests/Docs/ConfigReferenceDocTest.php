<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every config key must be in docs/configuration.md, with its default (issue #878, section 5).
 *
 * @coversNothing
 */
final class ConfigReferenceDocTest extends TestCase
{
    private const PAGE = 'docs/configuration.md';

    public static function tearDownAfterClass(): void
    {
        // Leave the garbage collector as it was: RebootStrategyTest counts collected cycles.
        gc_collect_cycles();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function leafProvider(): iterable
    {
        foreach (array_keys(DocsHelper::configLeaves()) as $path) {
            yield $path => [$path];
        }
    }

    public function testListKeysAreChecked(): void
    {
        self::assertArrayHasKey('trusted_hosts', DocsHelper::configLeaves());
        self::assertArrayHasKey('servers[].middlewares', DocsHelper::configLeaves());
    }

    #[DataProvider('leafProvider')]
    public function testKeyIsDocumentedWithItsDefault(string $path): void
    {
        $leaf = DocsHelper::configLeaves()[$path];

        $row = $this->findRow($path);
        self::assertNotNull($row, sprintf('%s has no table row for `%s`.', self::PAGE, $path));

        if ($leaf->isRequired()) {
            self::assertStringContainsString('required', strtolower($row), sprintf('`%s` is required, so its row must say so.', $path));

            return;
        }

        foreach (DocsHelper::defaultTokens($leaf) as $token) {
            self::assertStringContainsString(
                $token,
                $row,
                sprintf('The row of `%s` in %s does not show the default value "%s".', $path, self::PAGE, $token),
            );
        }
    }

    private function findRow(string $path): ?string
    {
        foreach (explode("\n", DocsHelper::read(self::PAGE)) as $line) {
            if (str_starts_with($line, '| `' . $path . '` |')) {
                return $line;
            }
        }

        return null;
    }
}
