<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Phar;

use CrazyGoat\WorkermanBundle\Phar\ExcludePattern;
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
        // Classic catastrophic-backtracking textbook cases (issue #334).
        yield 'plus over plus'         => ['(a+)+'];
        yield 'star over star'         => ['(a*)*b'];
        yield 'plus over any'          => ['(.+)+'];
        yield 'plus with lazy'         => ['(a+)?'];
        yield 'star over any'          => ['(.*)*'];
        yield 'plus over group with alt' => ['(.+|x)+'];
        yield 'bounded over plus'      => ['(a+){2,}'];
        yield 'star over plus'         => ['(a+)*'];
    }

    /**
     * @dataProvider provideNestedUnboundedQuantifiers
     */
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

        // Unbalanced character class — PCRE rejects this.
        new ExcludePattern('#src/[unterminated#');
    }

    public function testMatchesCompletesQuicklyUnderBacktrackLimit(): void
    {
        // The ReDoS safety net from #334: a pathological pattern that
        // somehow slipped past the structural check must not hang the build.
        // matches() runs @preg_match and treats a false result (limit
        // tripped) as "no match". The bounded pcre.backtrack_limit /
        // pcre.recursion_limit are established once per filtering pass by
        // PcreLimitGuard (issue #568); outside a pass, PHP's defaults
        // (1_000_000 / 100_000) match the guard's ceilings, so the same
        // bound applies and the call still completes quickly.
        $pattern = new ExcludePattern('#.*foo.*#');

        // Snapshot before, then deliberately use a known-bad pattern that
        // will trip the limit.
        $previous = ini_get('pcre.backtrack_limit');
        self::assertIsString($previous);

        $start = microtime(true);
        $pattern->matches(str_repeat('a', 1000) . str_repeat('b', 1000));
        $elapsed = microtime(true) - $start;

        // The call must complete quickly — if the guard is missing, the
        // pathological pattern could hang for seconds.
        self::assertLessThan(1.0, $elapsed, 'matches() took too long; backtrack-limit guard is not effective.');

        // matches() no longer touches the ini value itself (issue #568);
        // it must leave it exactly as it found it.
        self::assertSame($previous, ini_get('pcre.backtrack_limit'));
    }
}
