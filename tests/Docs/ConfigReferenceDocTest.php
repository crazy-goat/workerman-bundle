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

        $defaultCell = $this->defaultCell($row);
        foreach (DocsHelper::defaultTokens($leaf) as $token) {
            self::assertStringContainsString(
                '`' . $token . '`',
                $defaultCell,
                sprintf('The default column of `%s` in %s does not show `%s`.', $path, self::PAGE, $token),
            );
        }
    }

    public function testDefaultMustBeInTheDefaultColumnAsCodeSpan(): void
    {
        $row = '| `a.b` | int | `1000` | Reloads after 100 requests and 10 percent. |';

        self::assertSame(' `1000` ', $this->defaultCell($row));
        self::assertStringNotContainsString('`100`', $this->defaultCell($row));
        self::assertStringNotContainsString('`10`', $this->defaultCell($row));
    }

    private function defaultCell(string $row): string
    {
        return explode('|', $row)[3] ?? '';
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
