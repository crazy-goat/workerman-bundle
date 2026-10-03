<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Docs;

use CrazyGoat\WorkermanBundle\Http\Response\RequestMethodAwareResponseConverterStrategyInterface;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/events.md and docs/extending.md must name every event, its methods, every tag and the built-in strategy priorities (issue #878, section 5).
 *
 * @coversNothing
 */
final class EventsAndTagsDocTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, list<string>}>
     */
    public static function eventProvider(): iterable
    {
        foreach (glob(DocsHelper::rootDir() . '/src/Event/*.php') ?: [] as $file) {
            $class = 'CrazyGoat\\WorkermanBundle\\Event\\' . basename($file, '.php');
            self::assertTrue(class_exists($class));
            $methods = [];
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === $class && !$method->isConstructor()) {
                    $methods[] = $method->getName();
                }
            }

            yield basename($file, '.php') => [$class, $methods];
        }
    }

    /**
     * @param class-string $class
     * @param list<string> $methods
     */
    #[DataProvider('eventProvider')]
    public function testEventAndItsMethodsAreDocumented(string $class, array $methods): void
    {
        $page = DocsHelper::read('docs/events.md');

        self::assertStringContainsString('`' . $class . '`', $page);
        self::assertNotEmpty($methods);
        foreach ($methods as $method) {
            self::assertStringContainsString('`' . $method . '()`', $page, sprintf('docs/events.md does not mention %s::%s().', $class, $method));
        }
    }

    public function testTagsAreInTheExtensionTable(): void
    {
        $page = DocsHelper::read('docs/extending.md');

        foreach (['workerman.task', 'workerman.process', 'workerman.reboot_strategy', 'workerman.response_converter.strategy'] as $tag) {
            self::assertStringContainsString('`' . $tag . '`', $page);
        }
    }

    public function testBuiltInStrategyPrioritiesMatchTheCode(): void
    {
        $source = DocsHelper::read('src/DependencyInjection/ServicesConfigurator.php');
        $page = DocsHelper::read('docs/extending.md');

        self::assertSame(3, preg_match_all("/response_converter\\.strategy', \\['priority' => (\\d+)\\]/", $source, $matches));
        foreach ($matches[1] as $priority) {
            self::assertStringContainsString('| `' . $priority . '` |', $page);
        }
    }

    public function testStrategyInterfaceMethodsAndArgumentsAreDocumented(): void
    {
        $page = DocsHelper::read('docs/extending.md');

        self::assertStringContainsString('`' . ResponseConverterStrategyInterface::class . '`', $page);
        self::assertStringContainsString('`' . (new \ReflectionClass(RequestMethodAwareResponseConverterStrategyInterface::class))->getShortName() . '`', $page);
        foreach ((new \ReflectionClass(RequestMethodAwareResponseConverterStrategyInterface::class))->getMethod('convert')->getParameters() as $parameter) {
            self::assertStringContainsString('$' . $parameter->getName(), $page);
        }
    }

    public function testYamlExamplesParse(): void
    {
        $page = DocsHelper::read('docs/extending.md');

        self::assertGreaterThan(0, preg_match_all('/```yaml\n(.*?)```/s', $page, $blocks));
        foreach ($blocks[1] as $block) {
            $parsed = Yaml::parse($block);
            self::assertIsArray($parsed);
            foreach ($parsed['services'] as $definition) {
                foreach ($definition['tags'] as $tag) {
                    self::assertSame('workerman.response_converter.strategy', $tag['name']);
                    self::assertIsInt($tag['priority']);
                }
            }
        }
    }
}
