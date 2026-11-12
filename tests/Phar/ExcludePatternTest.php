<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Phar;

use CrazyGoat\WorkermanBundle\Phar\ExcludePattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExcludePatternTest extends TestCase
{
    public function testAcceptsPlainLiteralPattern(): void
    {
        $pattern = new ExcludePattern('#src/skip-#');
        self::assertTrue($pattern->matches('src/skip-me.php'));
        self::assertFalse($pattern->matches('src/keep-me.php'));
    }

    public function testAcceptsAnchoredPatternWithoutDelimiter(): void
    {
        $pattern = new ExcludePattern('^vendor/');
        self::assertTrue($pattern->matches('vendor/autoload.php'));
        self::assertFalse($pattern->matches('src/vendor.php'));
    }

    public function testAcceptsStandardGlobLikeRegex(): void
    {
        $pattern = new ExcludePattern('#\.git/#');
        self::assertTrue($pattern->matches('.git/HEAD'));
        self::assertFalse($pattern->matches('git/foo'));
    }

    /** @return iterable<string, array{0:string}> */
    public static function provideNestedUnboundedQuantifiers(): iterable
    {
        yield 'plus over plus'         => ['(a+)+'];
        yield 'star over star'         => ['(a*)*b'];
        yield 'plus over any'          => ['(.+)+'];
        yield 'plus with lazy'         => ['(a+)?'];
        yield 'star over any'          => ['(.*)*'];
        yield 'plus over group with alt' => ['(.+|x)+'];
        yield 'bounded over plus'      => ['(a+){2,}'];
        yield 'star over plus'         => ['(a+)*'];
    }

    #[DataProvider('provideNestedUnboundedQuantifiers')]
    #[DataProvider('provideGuardRejections')]
    public function testRejectsNestedUnboundedQuantifiersAtConstruction(string $rawInput): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nested unbounded quantifier');

        new ExcludePattern($rawInput);
    }

    /** @return iterable<string, array{0:string}> */
    public static function provideGuardRejections(): iterable
    {
        yield 'non-capturing group'            => ['(?:a+)+'];
        yield 'lookahead group'                => ['(?=a+)+'];
        yield 'negative lookahead group'       => ['(?!a+)*'];
        yield 'named group'                    => ['(?<name>a+)+'];
        yield 'inline flag group'              => ['(?i:a+)+'];
        yield 'bounded inner quantifier'       => ['(a{2})+'];
        yield 'bounded outer over bounded'     => ['(a{2}){3}'];
        yield 'quantifier inside nested group' => ['((a+)b)+'];
        yield 'outer group of nested pair'     => ['((ab)c+)*'];
        yield 'delimited pattern'              => ['#(a+)+#'];
        yield 'escaped char before quantifier' => ['(\.+)+'];
        yield 'class before quantifier'        => ['([ab]+)+'];
    }

    /** @return iterable<string, array{0:string, 1:string, 2:bool}> */
    public static function provideGuardAcceptances(): iterable
    {
        yield 'escaped quantifier in group'    => ['(a\+)+', 'a+a+', true];
        yield 'escaped parenthesis'            => ['\(a+\)', '(aa)', true];
        yield 'quantifier inside class'        => ['([+*?])+', '+*?', true];
        yield 'escaped bracket inside class'   => ['([\]+])+', ']+', true];
        yield 'parenthesis inside class'       => ['([()]a)+', '(a', true];
        yield 'group without inner quantifier' => ['(ab)+', 'abab', true];
        yield 'group bounded without inner'    => ['(ab){2}', 'abab', true];
        yield 'unterminated brace is literal'  => ['(a+){', 'aa{', true];
        yield 'lone brace without close'       => ['a{', 'a{', true];
        yield 'quantifier after closed group'  => ['(ab)c+', 'abcc', true];
        yield 'inline flag without group body' => ['(?i)a+', 'AA', true];
        yield 'named group without quantifier' => ['(?<n>ab)+', 'abab', true];
        yield 'atomic group'                   => ['(?>a+)', 'aa', true];
        yield 'lookbehind group'               => ['(?<=a)b', 'ab', false];
        yield 'escaped backslash'              => ['a\\\\', 'a\\', true];
        yield 'sequential groups'              => ['(a+)(b)+', 'abb', true];
    }

    #[DataProvider('provideGuardAcceptances')]
    public function testGuardAcceptsPatternsWithoutNestedUnboundedQuantifiers(string $raw, string $subject, bool $expected): void
    {
        self::assertSame($expected, (new ExcludePattern($raw))->matches($subject));
    }

    public function testRejectionMessageNamesTheRawPattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Exclude pattern "#(a+)+#" contains a nested unbounded quantifier '
            . '(e.g. "(a+)+", "(.+)*", "(a|a)+"). Such patterns can trigger '
            . 'catastrophic PCRE backtracking and would hang the PHAR build. '
            . 'Please rewrite the pattern using atomic groups ("(?>...)") '
            . 'or possessive quantifiers supported by PCRE, or constrain it '
            . 'with explicit non-nested bounds.',
        );

        new ExcludePattern('#(a+)+#');
    }

    public function testRejectsInvalidPcreAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid PCRE regex');

        new ExcludePattern('#src/[unterminated#');
    }

    public function testMatchesCompletesQuicklyUnderBacktrackLimit(): void
    {
        $pattern = new ExcludePattern('#.*foo.*#');

        $previous = ini_get('pcre.backtrack_limit');
        self::assertIsString($previous);

        $start = microtime(true);
        $pattern->matches(str_repeat('a', 1000) . str_repeat('b', 1000));
        $elapsed = microtime(true) - $start;

        self::assertLessThan(1.0, $elapsed, 'matches() took too long; backtrack-limit guard is not effective.');

        self::assertSame($previous, ini_get('pcre.backtrack_limit'));
    }
}
