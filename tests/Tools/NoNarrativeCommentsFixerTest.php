<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Tools;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class NoNarrativeCommentsFixerTest extends TestCase
{
    private static string $sandbox;

    private static string $projectDir;

    public static function setUpBeforeClass(): void
    {
        self::$projectDir = \dirname(__DIR__, 2);
        self::$sandbox = sys_get_temp_dir() . '/wmb-no-narrative-' . bin2hex(random_bytes(6));
        mkdir(self::$sandbox . '/cases', 0o777, true);

        $config = <<<'PHP'
            <?php

            declare(strict_types=1);

            require_once %s;

            return (new PhpCsFixer\Config())
                ->registerCustomFixers([new CrazyGoat\WorkermanBundle\Tools\PhpCsFixer\NoNarrativeCommentsFixer()])
                ->setRules([CrazyGoat\WorkermanBundle\Tools\PhpCsFixer\NoNarrativeCommentsFixer::NAME => true])
                ->setUsingCache(false)
                ->setUnsupportedPhpVersionAllowed(true);
            PHP;
        file_put_contents(
            self::$sandbox . '/config.php',
            \sprintf($config, var_export(self::$projectDir . '/tools/php-cs-fixer/autoload.php', true)),
        );
    }

    public static function tearDownAfterClass(): void
    {
        exec('rm -rf ' . escapeshellarg(self::$sandbox));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'summary-and-param-description' => [
                <<<'PHP'
                    <?php

                    /**
                     * Adds two numbers.
                     *
                     * Longer description.
                     *
                     * @param int $a the first operand
                     * @param int|null $b
                     */
                    function add(int $a, ?int $b): int
                    {
                        return $a;
                    }

                    PHP,
                <<<'PHP'
                    <?php

                    /**
                     * @param int $a
                     * @param int|null $b
                     */
                    function add(int $a, ?int $b): int
                    {
                        return $a;
                    }

                    PHP,
            ],
            'return-throws-var-descriptions' => [
                <<<'PHP'
                    <?php

                    final class A
                    {
                        /** @var list<string> names in order */
                        private array $names = [];

                        /**
                         * @return array<string, int> the totals
                         * @throws \RuntimeException when broken
                         */
                        public function totals(): array
                        {
                            return [];
                        }
                    }

                    PHP,
                <<<'PHP'
                    <?php

                    final class A
                    {
                        /** @var list<string> */
                        private array $names = [];

                        /**
                         * @return array<string, int>
                         * @throws \RuntimeException
                         */
                        public function totals(): array
                        {
                            return [];
                        }
                    }

                    PHP,
            ],
            'multi-line-array-shape' => [
                <<<'PHP'
                    <?php

                    /**
                     * @param array{
                     *     name: string,
                     *     items?: list<array{id: int, tags: array<string, bool>}>,
                     * } $config The configuration,
                     *   described over two lines.
                     * @return array{
                     *     ok: bool,
                     * } result
                     */
                    function run(array $config): array
                    {
                        return ['ok' => true];
                    }

                    PHP,
                <<<'PHP'
                    <?php

                    /**
                     * @param array{
                     *     name: string,
                     *     items?: list<array{id: int, tags: array<string, bool>}>,
                     * } $config
                     * @return array{
                     *     ok: bool,
                     * }
                     */
                    function run(array $config): array
                    {
                        return ['ok' => true];
                    }

                    PHP,
            ],
            'by-reference-variadic-and-param-out' => [
                <<<'PHP'
                    <?php

                    /**
                     * @param string|null &$out  Receives the value.
                     * @param int ...$rest variadic values
                     * @param-out string $out Always assigned.
                     */
                    function fill(?string &$out, int ...$rest): void
                    {
                        $out = '';
                    }

                    PHP,
                <<<'PHP'
                    <?php

                    /**
                     * @param string|null &$out
                     * @param int ...$rest
                     * @param-out string $out
                     */
                    function fill(?string &$out, int ...$rest): void
                    {
                        $out = '';
                    }

                    PHP,
            ],
            'phpstan-psalm-template-kept-verbatim' => [
                <<<'PHP'
                    <?php

                    /**
                     * A typed collection.
                     *
                     * @template T of object
                     * @phpstan-type Shape array{
                     *     a: int,
                     *     // @deprecated keep this line
                     *     b?: string,
                     * }
                     * @psalm-suppress PropertyNotSetInConstructor
                     * @phpstan-ignore-next-line missingType.generics
                     */
                    final class Collection
                    {
                    }

                    PHP,
                <<<'PHP'
                    <?php

                    /**
                     * @template T of object
                     * @phpstan-type Shape array{
                     *     a: int,
                     *     // @deprecated keep this line
                     *     b?: string,
                     * }
                     * @psalm-suppress PropertyNotSetInConstructor
                     * @phpstan-ignore-next-line missingType.generics
                     */
                    final class Collection
                    {
                    }

                    PHP,
            ],
            'deprecated-internal-see-lose-descriptions' => [
                <<<'PHP'
                    <?php

                    /**
                     * @internal used by the tests only
                     * @see Foo::bar() the dispatch site
                     * @deprecated since 1.0, use b() instead
                     * @author Someone <someone@example.com>
                     * @api
                     */
                    function a(): void
                    {
                    }

                    PHP,
                <<<'PHP'
                    <?php

                    /**
                     * @internal
                     * @see Foo::bar()
                     * @deprecated
                     */
                    function a(): void
                    {
                    }

                    PHP,
            ],
            'empty-docblocks-are-removed' => [
                <<<'PHP'
                    <?php

                    /**
                     * File header.
                     */

                    declare(strict_types=1);

                    /**
                     * @author Someone
                     */
                    final class A
                    {
                        /** {@inheritdoc} */
                        public function a(): void
                        {
                        }

                        /**
                         * Just a summary.
                         */
                        public function b(): void
                        {
                        }
                    }

                    PHP,
                <<<'PHP'
                    <?php

                    declare(strict_types=1);

                    final class A
                    {
                        public function a(): void
                        {
                        }

                        public function b(): void
                        {
                        }
                    }

                    PHP,
            ],
            'line-and-block-comments-are-removed' => [
                <<<'PHP'
                    <?php

                    // leading comment

                    function a(): int
                    {
                        // first line of the block
                        $x = 1; // trailing
                        # hash comment
                        /* block
                           comment */

                        $y = foo(/* inline */ 1, 2 /* tail */);

                        return $x + $y;
                        // last line of the block
                    }

                    PHP,
                <<<'PHP'
                    <?php

                    function a(): int
                    {
                        $x = 1;

                        $y = foo(1, 2);

                        return $x + $y;
                    }

                    PHP,
            ],
            'tool-directives-are-kept' => [
                <<<'PHP'
                    <?php

                    function a(): void
                    {
                        $y = foo(); // @phpstan-ignore argument.type
                        /** @phpstan-ignore-next-line */
                        bar();
                        // @codeCoverageIgnoreStart
                        baz();
                        // @codeCoverageIgnoreEnd
                        // phpcs:ignore Generic.Files.LineLength
                        qux(); /* @psalm-suppress MixedArgument */
                    }

                    PHP,
                <<<'PHP'
                    <?php

                    function a(): void
                    {
                        $y = foo(); // @phpstan-ignore argument.type
                        /** @phpstan-ignore-next-line */
                        bar();
                        // @codeCoverageIgnoreStart
                        baz();
                        // @codeCoverageIgnoreEnd
                        // phpcs:ignore Generic.Files.LineLength
                        qux(); /* @psalm-suppress MixedArgument */
                    }

                    PHP,
            ],
            'shebang-attributes-and-strings-are-untouched' => [
                <<<'PHP'
                    #!/usr/bin/env php
                    <?php

                    #[\Attribute]
                    final class Marker
                    {
                    }

                    $a = '// not a comment';
                    $b = "# not a comment /* nope */";
                    $c = <<<TXT
                        /** not a docblock */
                        // still text
                        TXT;

                    PHP,
                <<<'PHP'
                    #!/usr/bin/env php
                    <?php

                    #[\Attribute]
                    final class Marker
                    {
                    }

                    $a = '// not a comment';
                    $b = "# not a comment /* nope */";
                    $c = <<<TXT
                        /** not a docblock */
                        // still text
                        TXT;

                    PHP,
            ],
            'inline-var-docblock' => [
                <<<'PHP'
                    <?php

                    /** @var \Closure(int): string $format the formatter */
                    $format = static fn (int $i): string => (string) $i;
                    /** @var Foo */
                    $foo = make();

                    PHP,
                <<<'PHP'
                    <?php

                    /** @var \Closure(int): string $format */
                    $format = static fn (int $i): string => (string) $i;
                    /** @var Foo */
                    $foo = make();

                    PHP,
            ],
            'removing-a-comment-never-glues-tokens' => [
                <<<'PHP'
                    <?php

                    function a(): int
                    {
                        return/* why */1;
                    }

                    PHP,
                <<<'PHP'
                    <?php

                    function a(): int
                    {
                        return 1;
                    }

                    PHP,
            ],
            'removing-a-comment-never-glues-operators' => [
                <<<'PHP'
                    <?php

                    $r = $a-/* x */-$b;
                    $r = $c+/**/+$d;
                    $r = $e- /* y */-$f;
                    $r = $g-/* z */ -$h;
                    $r = $x/* */+ +$y;
                    $r = $i./* w */5;

                    PHP,
                <<<'PHP'
                    <?php

                    $r = $a- -$b;
                    $r = $c+ +$d;
                    $r = $e- -$f;
                    $r = $g- -$h;
                    $r = $x+ +$y;
                    $r = $i. 5;

                    PHP,
            ],
            'directives-must-start-the-comment' => [
                <<<'PHP'
                    <?php

                    function a(): void
                    {
                        // we used to need phpcs: here
                        foo();
                        // remove @phpstan-ignore once the stub is fixed
                        bar(); # see @psalm-suppress notes
                        /* old @codeCoverageIgnore marker */
                        baz(); /*  @phpstan-ignore argument.type */
                        #   @codeCoverageIgnore
                        qux(); /**
                         * @phpstan-ignore-next-line
                         */
                    }

                    PHP,
                <<<'PHP'
                    <?php

                    function a(): void
                    {
                        foo();
                        bar();
                        baz(); /*  @phpstan-ignore argument.type */
                        #   @codeCoverageIgnore
                        qux(); /**
                         * @phpstan-ignore-next-line
                         */
                    }

                    PHP,
            ],
            'one-line-docblock-keeps-every-tag' => [
                <<<'PHP'
                    <?php

                    /** @param int $a the first @param int $b the second @return int sum */
                    function add(int $a, int $b): int
                    {
                        return $a + $b;
                    }

                    PHP,
                <<<'PHP'
                    <?php

                    /** @param int $a @param int $b @return int */
                    function add(int $a, int $b): int
                    {
                        return $a + $b;
                    }

                    PHP,
            ],
            'test-annotations-are-left-for-the-attribute-migration' => [
                <<<'PHP'
                    <?php

                    final class ATest
                    {
                        /**
                         * Runs only with inotify.
                         *
                         * @requires extension inotify
                         * @group e2e
                         */
                        public function testA(): void
                        {
                        }

                        /** Provider-driven. @dataProvider cases */
                        public function testB(int $a): void
                        {
                        }
                    }

                    /**
                     * Benchmarks.
                     *
                     * @BeforeMethods({"setUp"})
                     * @Revs(1000)
                     * @Bench\Iterations(5)
                     */
                    final class ABench
                    {
                    }

                    PHP,
                <<<'PHP'
                    <?php

                    final class ATest
                    {
                        /**
                         * Runs only with inotify.
                         *
                         * @requires extension inotify
                         * @group e2e
                         */
                        public function testA(): void
                        {
                        }

                        /** Provider-driven. @dataProvider cases */
                        public function testB(int $a): void
                        {
                        }
                    }

                    /**
                     * Benchmarks.
                     *
                     * @BeforeMethods({"setUp"})
                     * @Revs(1000)
                     * @Bench\Iterations(5)
                     */
                    final class ABench
                    {
                    }

                    PHP,
            ],
            'already-clean-code-is-unchanged' => [
                <<<'PHP'
                    <?php

                    /**
                     * @param list<int> $values
                     * @return int
                     */
                    function sum(array $values): int
                    {
                        return array_sum($values);
                    }

                    PHP,
                <<<'PHP'
                    <?php

                    /**
                     * @param list<int> $values
                     * @return int
                     */
                    function sum(array $values): int
                    {
                        return array_sum($values);
                    }

                    PHP,
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testFixerOutput(string $input, string $expected): void
    {
        $file = self::$sandbox . '/cases/' . $this->dataName() . '.php';
        file_put_contents($file, $input);

        [$code, $output] = $this->runFixer($file, false);
        $this->assertSame(0, $code, $output);
        $this->assertSame($expected, file_get_contents($file));

        [$code, $output] = $this->runFixer($file, true);
        $this->assertSame(0, $code, 'The fixer must be idempotent: ' . $output);

        $this->assertSame(
            $this->codeTokens($input),
            $this->codeTokens((string) file_get_contents($file)),
            'Removing comments must never change the non-comment token stream',
        );
    }

    public function testNoAllowedTagIsEverDropped(): void
    {
        $tags = [
            '@param int $a',
            '@param array<string, list<int>> $map',
            '@param string|null &$out',
            '@param int ...$rest',
            '@param-out string $out',
            '@return array{ok: bool}',
            '@return static',
            '@throws \\RuntimeException',
            '@var list<string>',
            '@var Foo $foo',
            '@deprecated',
            '@internal',
            '@see Foo::bar()',
            '@phpstan-param positive-int $a the count',
            '@phpstan-return list<int>',
            '@phpstan-impure',
            '@psalm-suppress MixedArgument',
            '@template T of object',
            '@phpstan-ignore-next-line missingType.generics',
            '@codeCoverageIgnore',
        ];

        $code = "<?php\n\n";
        $expected = [];
        foreach ($tags as $position => $tag) {
            $code .= "/**\n * Summary {$position}.\n *\n * {$tag} some description\n */\nfunction multi{$position}(): void\n{\n}\n\n";
            $code .= "/** {$tag} described @internal also */\nfunction single{$position}(): void\n{\n}\n\n";
            $code .= "/**\n * @internal\n * {$tag}\n * @deprecated\n */\nfunction bare{$position}(): void\n{\n}\n\n";
            $expected[] = str_starts_with($tag, '@phpstan-') || str_starts_with($tag, '@psalm-') || str_starts_with($tag, '@template')
                ? $tag . ' some description'
                : $tag;
        }

        $file = self::$sandbox . '/all-tags.php';
        file_put_contents($file, $code);
        [$exitCode, $output] = $this->runFixer($file, false);
        $this->assertSame(0, $exitCode, $output);
        $fixed = (string) file_get_contents($file);

        foreach ($tags as $position => $tag) {
            $this->assertStringContainsString(' * ' . $expected[$position] . "\n */\nfunction multi{$position}()", $fixed, $tag . ' was dropped from a multi-line docblock');
            $this->assertMatchesRegularExpression('~/\*\* ' . preg_quote($tag, '~') . '.* @internal \*/\nfunction single' . $position . '\(~', $fixed, $tag . ' was dropped from a one-line docblock');
            $this->assertStringContainsString("/**\n * @internal\n * {$tag}\n * @deprecated\n */\nfunction bare{$position}()", $fixed, $tag . ' was dropped from a docblock of bare tags');
        }
    }

    public function testUnparsableTypeFailsLoudly(): void
    {
        $file = self::$sandbox . '/unparsable.php';
        $input = "<?php\n\n/**\n * @return array<int the list\n */\nfunction a(): array\n{\n    return [];\n}\n";
        file_put_contents($file, $input);

        foreach ([true, false] as $dryRun) {
            [$code, $output] = $this->runFixer($file, $dryRun);
            $this->assertNotSame(0, $code, 'An unparsable type must fail php-cs-fixer');
            $this->assertStringContainsString('cannot parse the type of "@return array<int the list"', $output);
            $this->assertSame($input, file_get_contents($file));
        }
    }

    public function testDryRunReportsViolation(): void
    {
        $file = self::$sandbox . '/violation.php';
        file_put_contents($file, "<?php\n\n// narrative\n\$a = 1;\n");

        [$code] = $this->runFixer($file, true);

        $this->assertNotSame(0, $code, 'php-cs-fixer --dry-run must fail on a narrative comment');
        $this->assertSame("<?php\n\n// narrative\n\$a = 1;\n", file_get_contents($file));
    }

    public function testProjectConfigRegistersTheFixerForEveryPhpDirectory(): void
    {
        $config = (string) file_get_contents(self::$projectDir . '/.php-cs-fixer.dist.php');

        $this->assertStringContainsString('->registerCustomFixers([new NoNarrativeCommentsFixer()])', $config);
        $this->assertStringContainsString('NoNarrativeCommentsFixer::NAME => true', $config);
        foreach (['src', 'tests', 'benchmarks', 'bin', 'e2e', 'tools'] as $directory) {
            $this->assertStringContainsString("->in(__DIR__ . '/" . $directory . "')", $config);
        }

        $phpstan = (string) file_get_contents(self::$projectDir . '/phpstan.neon.dist');
        $this->assertMatchesRegularExpression('/^\s*-\s+tools\s*$/m', $phpstan);
    }

    /**
     * @return list<string>
     */
    private function codeTokens(string $code): array
    {
        $result = [];
        foreach (token_get_all($code) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT, \T_WHITESPACE], true)) {
                continue;
            }
            $result[] = \is_array($token) ? $token[1] : $token;
        }

        return $result;
    }

    /**
     * @return array{int, string}
     */
    private function runFixer(string $file, bool $dryRun): array
    {
        $command = \sprintf(
            '%s %s fix -vv --config=%s --path-mode=override --sequential %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::$projectDir . '/vendor/bin/php-cs-fixer'),
            escapeshellarg(self::$sandbox . '/config.php'),
            $dryRun ? '--dry-run' : '',
            escapeshellarg($file),
        );
        exec($command, $output, $code);

        return [$code, implode("\n", $output)];
    }
}
