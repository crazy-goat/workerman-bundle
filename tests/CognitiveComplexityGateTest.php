<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CognitiveComplexityGateTest extends TestCase
{
    private const CONFIG = 'phpstan.neon.dist';

    private const EXTENSION = 'vendor/tomasvotruba/cognitive-complexity/config/extension.neon';

    private const FUNCTION_LIMIT_CEILING = 50;

    private const CLASS_LIMIT_CEILING = 150;

    private const DECLARATION_TOKENS = [\T_FUNCTION, \T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM, \T_ATTRIBUTE];

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = \dirname(__DIR__);
    }

    public function testTheExtensionIsTheOnlyInclude(): void
    {
        self::assertSame(
            ['- ' . self::EXTENSION],
            $this->neonEntries($this->neonBlock($this->config(), 'includes')),
            self::CONFIG . ' must include the cognitive-complexity extension explicitly (phpstan/extension-installer is not installed) and nothing else that could override cognitive_complexity',
        );
    }

    public function testTheLimitsAreSetInTheCognitiveComplexityBlockAndOnlyGoDown(): void
    {
        $limits = [];
        foreach ($this->neonEntries($this->neonBlock($this->config(), 'parameters', 'cognitive_complexity')) as $entry) {
            if (preg_match('/^(\w+):\s*(\d+)$/', $entry, $match) !== 1) {
                self::fail('Unexpected cognitive_complexity entry: ' . $entry);
            }

            $limits[$match[1]] = (int) $match[2];
        }

        ksort($limits);
        self::assertSame(['class', 'function'], array_keys($limits), 'parameters.cognitive_complexity must set exactly function and class');
        self::assertLessThanOrEqual(self::FUNCTION_LIMIT_CEILING, $limits['function'], 'The function complexity limit only goes down');
        self::assertLessThanOrEqual(self::CLASS_LIMIT_CEILING, $limits['class'], 'The class complexity limit only goes down');
    }

    public function testTheConfigHasNoBaselineAndNoComplexityIgnore(): void
    {
        $config = $this->config();

        self::assertStringNotContainsStringIgnoringCase('baseline', $config, 'No PHPStan baseline: violations are refactored, not recorded');
        self::assertStringNotContainsString('complexity.', $config, 'No complexity.* identifier may be ignored');
        self::assertDoesNotMatchRegularExpression('/cognitive complexity/i', $config, 'No complexity message may be ignored');
        self::assertStringNotContainsString('excludePaths', $config, 'No path may be excluded from analysis');

        preg_match_all('/^[ \t]+(?:-[ \t]+)?(?:message|messages|identifier|identifiers|rawMessage):.*$/m', $config, $ignores);
        self::assertSame(
            [
                "message: '#never returns string so it can be removed from the return type#'",
                "message: '#Call to method.*on a separate line has no effect#'",
            ],
            array_map(trim(...), $ignores[0]),
            'ignoreErrors may only hold the reviewed entries; a new ignore must not be able to silence the complexity rules',
        );
    }

    public function testNoOtherPhpstanConfigIsTracked(): void
    {
        self::assertSame([self::CONFIG], $this->gitLsFiles([self::CONFIG]), 'git ls-files must work for this check to mean anything');

        foreach (['phpstan.neon', 'phpstan.dist.neon'] as $config) {
            self::assertSame([], $this->gitLsFiles([$config]), $config . ' takes precedence over ' . self::CONFIG . ' in bin/lint.sh and must not be tracked');
        }
    }

    public function testThePackageIsPinnedToAnExactVersion(): void
    {
        $composer = json_decode((string) file_get_contents($this->projectDir . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($composer);
        self::assertSame('1.3.0', $composer['require-dev']['tomasvotruba/cognitive-complexity'] ?? null);
    }

    public function testNoInlineIgnoreCanSuppressTheComplexityRule(): void
    {
        $paths = array_map(
            static fn(string $entry): string => trim(substr($entry, 2)),
            $this->neonEntries($this->neonBlock($this->config(), 'parameters', 'paths')),
        );
        self::assertContains('src', $paths);

        $files = array_values(array_filter($this->gitLsFiles($paths), static fn(string $file): bool => str_ends_with($file, '.php')));
        self::assertNotEmpty($files);

        $violations = [];
        foreach ($files as $file) {
            foreach ($this->complexitySuppressingIgnores((string) file_get_contents($this->projectDir . '/' . $file)) as $line) {
                $violations[] = $file . ':' . $line;
            }
        }

        self::assertSame([], $violations, "These @phpstan-ignore comments name complexity.* or cover a function, class or attribute line, so they can silence the cognitive-complexity rule:\n" . implode("\n", $violations));
    }

    /** @return iterable<string, array{0: string, 1: list<int>}> */
    public static function provideIgnoreScanCases(): iterable
    {
        yield 'identifier in docblock' => ["<?php\n/** @phpstan-ignore complexity.functionLike */\nfunction a() {}\n", [2]];
        yield 'class identifier in line comment' => ["<?php\n// @phpstan-ignore complexity.classLike\nfinal class A {}\n", [2]];
        yield 'identifier among others' => ["<?php\nfunction a() {} // @phpstan-ignore return.type, complexity.functionLike\n", [2]];
        yield 'bare next-line docblock on function' => ["<?php\n/**\n * @phpstan-ignore-next-line\n */\nfunction a() {}\n", [2]];
        yield 'next-line with trailing text on method' => ["<?php\nclass A {\n    /** @phpstan-ignore-next-line missingType.generics */\n    public function a() {}\n}\n", [3]];
        yield 'next-line on attribute' => ["<?php\n// @phpstan-ignore-next-line\n#[\\Deprecated]\nfunction a() {}\n", [2]];
        yield 'ignore-line on declaration' => ["<?php\nfunction a() {} // @phpstan-ignore-line\n", [2]];
        yield 'next-line on class' => ["<?php\n// @phpstan-ignore-next-line\nfinal class A {}\n", [2]];
        yield 'next-line on statement' => ["<?php\n/** @phpstan-ignore-next-line */\nnew A();\n", []];
        yield 'next-line on class constant fetch' => ["<?php\n// @phpstan-ignore-next-line\n\$a = A::class;\n", []];
        yield 'identifier ignore on declaration' => ["<?php\n/** @phpstan-ignore missingType.generics */\nfunction a() {}\n", []];
        yield 'directive inside a string' => ["<?php\n\$a = '// @phpstan-ignore complexity.functionLike';\nfunction a() {}\n", []];
        yield 'no ignore' => ["<?php\n// plain\nfunction a() {}\n", []];
    }

    /** @param list<int> $expectedLines */
    #[DataProvider('provideIgnoreScanCases')]
    public function testTheIgnoreScanFlagsOnlySuppressingComments(string $source, array $expectedLines): void
    {
        self::assertSame($expectedLines, $this->complexitySuppressingIgnores($source));
    }

    /** @return list<int> */
    private function complexitySuppressingIgnores(string $source): array
    {
        $tokens = token_get_all($source);
        $declarationLines = $this->declarationLines($tokens);
        $lines = [];

        foreach ($tokens as $token) {
            if (!\is_array($token) || !\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            if ($this->ignoreNamesComplexity($token[1]) || $this->ignoreCoversDeclaration($token[1], $token[2], $declarationLines)) {
                $lines[] = $token[2];
            }
        }

        return $lines;
    }

    private function ignoreNamesComplexity(string $comment): bool
    {
        return preg_match('/@phpstan-ignore\b[^\n]*\bcomplexity\./', $comment) === 1;
    }

    /** @param array<int, true> $declarationLines */
    private function ignoreCoversDeclaration(string $comment, int $startLine, array $declarationLines): bool
    {
        $endLine = $startLine + substr_count(rtrim($comment), "\n");
        $covered = [];

        if (str_contains($comment, '@phpstan-ignore-next-line')) {
            $covered[] = $endLine + 1;
        }

        if (str_contains($comment, '@phpstan-ignore-line')) {
            $covered = [...$covered, ...range($startLine, $endLine)];
        }

        return array_intersect_key($declarationLines, array_flip($covered)) !== [];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<int, true>
     */
    private function declarationLines(array $tokens): array
    {
        $lines = [];
        $previous = null;

        foreach ($tokens as $token) {
            if (!\is_array($token) || \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            if (\in_array($token[0], self::DECLARATION_TOKENS, true) && $previous !== \T_DOUBLE_COLON) {
                $lines[$token[2]] = true;
            }

            $previous = $token[0];
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function neonEntries(array $lines): array
    {
        $indent = $this->minimalIndent($lines);

        return array_values(array_map(
            trim(...),
            array_filter($lines, static fn(string $line): bool => self::indentOf($line) === $indent),
        ));
    }

    /** @return list<string> */
    private function neonBlock(string $neon, string ...$path): array
    {
        $lines = array_values(array_filter(
            preg_split('/\R/', $neon) ?: [],
            static fn(string $line): bool => trim($line) !== '' && !str_starts_with(ltrim($line), '#'),
        ));

        foreach ($path as $key) {
            $lines = $this->neonChildren($lines, $key);
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function neonChildren(array $lines, string $key): array
    {
        $indent = $this->minimalIndent($lines);
        $children = [];
        $inside = false;

        foreach ($lines as $line) {
            if (self::indentOf($line) === $indent) {
                $inside = trim($line) === $key . ':';

                continue;
            }

            if ($inside) {
                $children[] = $line;
            }
        }

        return $children;
    }

    /** @param list<string> $lines */
    private function minimalIndent(array $lines): int
    {
        return $lines === [] ? 0 : min(array_map(self::indentOf(...), $lines));
    }

    private static function indentOf(string $line): int
    {
        return \strlen($line) - \strlen(ltrim($line, " \t"));
    }

    private function config(): string
    {
        return (string) file_get_contents($this->projectDir . '/' . self::CONFIG);
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function gitLsFiles(array $paths): array
    {
        $output = shell_exec(sprintf(
            'git -C %s ls-files -- %s',
            escapeshellarg($this->projectDir),
            implode(' ', array_map(escapeshellarg(...), $paths)),
        ));

        return \is_string($output) ? array_values(array_filter(explode("\n", $output), static fn(string $file): bool => $file !== '')) : [];
    }
}
