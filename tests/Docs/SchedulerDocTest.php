<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Docs;

use CrazyGoat\WorkermanBundle\Attribute\AsTask;
use CrazyGoat\WorkermanBundle\Event\TaskErrorEvent;
use CrazyGoat\WorkermanBundle\Event\TaskStartEvent;
use CrazyGoat\WorkermanBundle\Scheduler\Trigger\TriggerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/scheduler.md must name the attribute parameters, the tag, the events and only show schedules that work (issue #878, section 5).
 *
 * @coversNothing
 */
final class SchedulerDocTest extends TestCase
{
    private const PAGE = 'docs/scheduler.md';

    /**
     * @return iterable<string, array{string}>
     */
    public static function attributeParameterProvider(): iterable
    {
        foreach ((new \ReflectionClass(AsTask::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
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

        foreach (['workerman.task', TaskStartEvent::class, TaskErrorEvent::class, '`task`', 'dragonmantank/cron-expression'] as $needle) {
            self::assertStringContainsString($needle, $page);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function scheduleProvider(): iterable
    {
        foreach (['60 seconds', 'PT60S', 'PT1M', '1 minute', '2023-08-01T01:00:00+08:00'] as $schedule) {
            yield $schedule => [$schedule];
        }
    }

    #[DataProvider('scheduleProvider')]
    public function testScheduleExampleIsOnThePageAndValid(string $schedule): void
    {
        self::assertStringContainsString($schedule . '`', DocsHelper::read(self::PAGE));

        self::assertNotSame('', (string) TriggerFactory::create($schedule));
    }

    public function testIntegerScheduleIsDocumentedForYamlOnly(): void
    {
        self::assertStringContainsString('| Integer | `60` | Every 60 seconds. Only in YAML', DocsHelper::read(self::PAGE));
        self::assertNotSame('', (string) TriggerFactory::create(60));
    }

    public function testTaskAttributesInPhpExamplesAreValid(): void
    {
        $page = DocsHelper::read(self::PAGE);

        self::assertGreaterThan(0, preg_match_all("/#\\[AsTask\\(.*schedule: '([^']+)'.*\\)\\]/", $page, $matches));
        foreach ($matches[1] as $schedule) {
            self::assertNotSame('', (string) TriggerFactory::create($schedule));
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
                    // The second form is `{ workerman.task: { attributes } }`; in the first form `name` is the tag name.
                    $attributes = $tag['workerman.task'] ?? $tag;
                    self::assertTrue(isset($tag['workerman.task']) || ($tag['name'] ?? null) === 'workerman.task');
                    unset($attributes['name']);
                    if (isset($tag['workerman.task'])) {
                        self::assertContains('name', array_keys($tag['workerman.task']));
                    }

                    self::assertSame([], array_diff(array_keys($attributes), ['schedule', 'method', 'jitter']));
                    self::assertTrue(is_int($attributes['schedule']) || is_string($attributes['schedule']));
                    self::assertIsInt($attributes['jitter'] ?? 0);
                    self::assertNotSame('', (string) TriggerFactory::create($attributes['schedule'], $attributes['jitter'] ?? 0));
                }
            }
        }
    }
}
