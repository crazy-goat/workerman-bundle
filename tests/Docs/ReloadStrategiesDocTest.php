<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Docs;

use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/reload-strategies.md must have a section for every reload strategy and name every option of it (issue #878, section 5).
 *
 * @coversNothing
 */
final class ReloadStrategiesDocTest extends TestCase
{
    private const PAGE = 'docs/reload-strategies.md';

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function strategyProvider(): iterable
    {
        $options = [];
        foreach (array_keys(DocsHelper::configLeaves()) as $path) {
            if (preg_match('/^reload_strategy\.([a-z_]+)\.([a-z_]+)$/', $path, $match) === 1) {
                $options[$match[1]][] = $match[2];
            }
        }

        self::assertNotEmpty($options);

        foreach ($options as $strategy => $keys) {
            yield $strategy => [$strategy, $keys];
        }
    }

    /**
     * @param list<string> $keys
     */
    #[DataProvider('strategyProvider')]
    public function testStrategyHasASectionWithItsOptions(string $strategy, array $keys): void
    {
        $page = DocsHelper::read(self::PAGE);

        $start = strpos($page, "\n## " . $strategy . "\n");
        self::assertIsInt($start, sprintf('%s has no section for `%s`.', self::PAGE, $strategy));
        $end = strpos($page, "\n## ", $start + 1);
        $section = $end === false ? substr($page, $start) : substr($page, $start, $end - $start);

        foreach ($keys as $key) {
            if ($key === 'active') {
                continue;
            }

            self::assertStringContainsString('`' . $key . '`', $section, sprintf('The section `%s` of %s does not mention `%s`.', $strategy, self::PAGE, $key));
        }
    }

    public function testCustomStrategyInterfaceAndTagAreNamed(): void
    {
        $page = DocsHelper::read(self::PAGE);

        self::assertStringContainsString(RebootStrategyInterface::class, $page);
        self::assertStringContainsString('workerman.reboot_strategy', DocsHelper::read('src/DependencyInjection/ServicesConfigurator.php'));
        self::assertStringContainsString('`workerman.reboot_strategy`', $page);
    }
}
