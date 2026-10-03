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
 * docs/scheduler.md must name the attribute parameters, the tag, the events and only show schedules that work (issue #878, section 7).
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
        foreach (['60', 'PT1M', '1 minute', '2023-08-01T01:00:00+08:00'] as $schedule) {
            yield $schedule => [$schedule];
        }
    }

    #[DataProvider('scheduleProvider')]
    public function testScheduleExampleIsOnThePageAndValid(string $schedule): void
    {
        self::assertStringContainsString('`' . $schedule . '`', DocsHelper::read(self::PAGE));

        $value = ctype_digit($schedule) ? (int) $schedule : $schedule;
        self::assertNotSame('', (string) TriggerFactory::create($value));
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
                    self::assertSame('workerman.task', $tag['name']);
                    self::assertSame([], array_diff(array_keys($tag), ['name', 'schedule', 'method', 'jitter']));
                    self::assertTrue(is_int($tag['schedule']) || is_string($tag['schedule']));
                    self::assertIsInt($tag['jitter'] ?? 0);
                    self::assertNotSame('', (string) TriggerFactory::create($tag['schedule'], $tag['jitter'] ?? 0));
                }
            }
        }
    }
}
