<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Docs;

use CrazyGoat\WorkermanBundle\Attribute\AsProcess;
use CrazyGoat\WorkermanBundle\Event\ProcessErrorEvent;
use CrazyGoat\WorkermanBundle\Event\ProcessStartEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/supervisor.md must name the attribute parameters, the tag and the events, and its YAML examples must be valid (issue #878, section 5).
 *
 * @coversNothing
 */
final class SupervisorDocTest extends TestCase
{
    private const PAGE = 'docs/supervisor.md';

    /**
     * @return iterable<string, array{string}>
     */
    public static function attributeParameterProvider(): iterable
    {
        foreach ((new \ReflectionClass(AsProcess::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            yield $parameter->getName() => [$parameter->getName()];
        }
    }

    #[DataProvider('attributeParameterProvider')]
    public function testAttributeParameterIsDocumented(string $name): void
    {
        self::assertStringContainsString('| `' . $name . '` |', DocsHelper::read(self::PAGE));
    }

    public function testTagEventsAndChannelAreNamed(): void
    {
        $page = DocsHelper::read(self::PAGE);

        foreach (['workerman.process', ProcessStartEvent::class, ProcessErrorEvent::class, '`process`', 'finished unexpectedly'] as $needle) {
            self::assertStringContainsString($needle, $page);
        }
    }

    public function testYamlExamplesParseAndUseKnownTagAttributes(): void
    {
        $page = DocsHelper::read(self::PAGE);

        self::assertGreaterThan(0, preg_match_all('/```yaml\n(.*?)```/s', $page, $blocks));
        foreach ($blocks[1] as $block) {
            $parsed = Yaml::parse($block);
            self::assertIsArray($parsed);
            foreach ($parsed['services'] as $definition) {
                foreach ($definition['tags'] as $tag) {
                    // The second form is `{ workerman.process: { attributes } }`; in the first form `name` is the tag name.
                    self::assertTrue(isset($tag['workerman.process']) || ($tag['name'] ?? null) === 'workerman.process');
                    $attributes = $tag['workerman.process'] ?? $tag;
                    if (!isset($tag['workerman.process'])) {
                        unset($attributes['name']);
                    }

                    self::assertSame([], array_diff(array_keys($attributes), ['name', 'processes', 'method']));
                    self::assertIsInt($attributes['processes'] ?? 1);
                }
            }
        }
    }
}
