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
    public function testRejectsNestedUnboundedQuantifiersAtConstruction(string $rawInput): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nested unbounded quantifier');

        new ExcludePattern($rawInput);
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
