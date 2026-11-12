<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Tools\PhpCsFixer;

final class TestAnnotationTags
{
    public const PHPUNIT = [
        'after', 'afterClass', 'backupGlobals', 'backupStaticAttributes', 'backupStaticProperties', 'before', 'beforeClass',
        'covers', 'coversClass', 'coversDefaultClass', 'coversFunction', 'coversNothing', 'dataProvider', 'dataProviderExternal',
        'depends', 'dependsExternal', 'dependsOnClass', 'doesNotPerformAssertions', 'excludeGlobalVariableFromBackup',
        'excludeStaticPropertyFromBackup', 'group', 'ignoreDeprecations', 'large', 'medium', 'postCondition', 'preCondition',
        'preserveGlobalState', 'requires', 'runClassInSeparateProcess', 'runInSeparateProcess', 'runTestsInSeparateProcesses',
        'small', 'test', 'testdox', 'testWith', 'ticket', 'uses', 'usesClass', 'usesFunction',
    ];

    public const PHPBENCH = [
        'AfterClassMethods', 'AfterMethods', 'Assert', 'BeforeClassMethods', 'BeforeMethods', 'Executor', 'Format', 'Groups',
        'Iterations', 'OutputMode', 'OutputTimeUnit', 'ParamProviders', 'RetryThreshold', 'Revs', 'Skip', 'Sleep', 'Subject',
        'Timeout', 'Warmup', 'Worker',
    ];

    public static function regex(): string
    {
        $names = array_map(static fn(string $tag): string => preg_quote($tag, '~'), [...self::PHPUNIT, ...self::PHPBENCH]);

        return '~(?:^|[\s*])@(' . implode('|', $names) . ')(?![\w-])~m';
    }

    public static function find(string $docComment): ?string
    {
        if (preg_match(self::regex(), $docComment, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
