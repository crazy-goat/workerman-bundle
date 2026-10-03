<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Docs;

use CrazyGoat\WorkermanBundle\Command\ServerAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Every console command, every long option and every server action must be in docs/commands.md (issue #878, section 5).
 *
 * @coversNothing
 */
final class CommandReferenceDocTest extends TestCase
{
    private const PAGE = 'docs/commands.md';

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function commandProvider(): iterable
    {
        foreach (glob(DocsHelper::rootDir() . '/src/Command/*Command.php') ?: [] as $file) {
            /** @var class-string $class */
            $class = 'CrazyGoat\\WorkermanBundle\\Command\\' . basename($file, '.php');
            $attributes = (new \ReflectionClass($class))->getAttributes(AsCommand::class);
            if ($attributes === []) {
                continue;
            }

            $name = $attributes[0]->newInstance()->name;
            self::assertIsString($name);

            preg_match_all("/->addOption\\('([a-z][a-z-]*)'/", (string) file_get_contents($file), $matches);

            yield $name => [$name, array_values($matches[1])];
        }
    }

    /**
     * @param list<string> $options
     */
    #[DataProvider('commandProvider')]
    public function testCommandAndOptionsAreDocumented(string $name, array $options): void
    {
        $page = DocsHelper::read(self::PAGE);

        self::assertStringContainsString('`' . $name . '`', $page, sprintf('%s does not mention the command `%s`.', self::PAGE, $name));

        // Look for the options only in the section of the command: some options exist on more than one command.
        $start = strpos($page, '## `' . $name . '`');
        self::assertIsInt($start, sprintf('%s has no section for `%s`.', self::PAGE, $name));
        $end = strpos($page, "\n## ", $start + 1);
        $section = $end === false ? substr($page, $start) : substr($page, $start, $end - $start);

        foreach ($options as $option) {
            self::assertStringContainsString(
                '`--' . $option,
                $section,
                sprintf('%s does not mention the option `--%s` of `%s`.', self::PAGE, $option, $name),
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actionProvider(): iterable
    {
        foreach (ServerAction::values() as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('actionProvider')]
    public function testServerActionIsDocumented(string $action): void
    {
        self::assertStringContainsString(
            '| `' . $action . '` |',
            DocsHelper::read(self::PAGE),
            sprintf('%s has no table row for the action `%s`.', self::PAGE, $action),
        );
    }

    public function testTheTestFindsAllCommands(): void
    {
        $names = array_keys(iterator_to_array(self::commandProvider()));
        sort($names);

        self::assertSame(['workerman:build:bin', 'workerman:build:phar', 'workerman:server'], $names);
    }
}
