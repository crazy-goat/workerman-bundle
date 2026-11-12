<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Phar;

use CrazyGoat\WorkermanBundle\Phar\ExcludePattern;
use CrazyGoat\WorkermanBundle\Phar\PcreLimitGuard;
use PHPUnit\Framework\TestCase;

final class PcreLimitGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        ini_restore('pcre.backtrack_limit');
        ini_restore('pcre.recursion_limit');
    }

    public function testEnterSetsBoundedLimits(): void
    {
        $guard = new PcreLimitGuard();
        $guard->enter();

        try {
            self::assertSame((string) ExcludePattern::BACKTRACK_LIMIT, ini_get('pcre.backtrack_limit'));
            self::assertSame((string) ExcludePattern::RECURSION_LIMIT, ini_get('pcre.recursion_limit'));
        } finally {
            $guard->exit();
        }
    }

    public function testExitRestoresPriorValues(): void
    {
        $originalBacktrack = ini_get('pcre.backtrack_limit');
        $originalRecursion = ini_get('pcre.recursion_limit');
        self::assertIsString($originalBacktrack);
        self::assertIsString($originalRecursion);

        ini_set('pcre.backtrack_limit', '999');
        ini_set('pcre.recursion_limit', '888');

        $guard = new PcreLimitGuard();
        $guard->enter();
        $guard->exit();

        self::assertSame('999', ini_get('pcre.backtrack_limit'));
        self::assertSame('888', ini_get('pcre.recursion_limit'));

        ini_set('pcre.backtrack_limit', $originalBacktrack);
        ini_set('pcre.recursion_limit', $originalRecursion);
    }

    public function testExitRestoresEvenWhenProtectedBlockThrows(): void
    {
        $originalBacktrack = ini_get('pcre.backtrack_limit');
        $originalRecursion = ini_get('pcre.recursion_limit');
        self::assertIsString($originalBacktrack);
        self::assertIsString($originalRecursion);

        ini_set('pcre.backtrack_limit', '777');
        ini_set('pcre.recursion_limit', '666');

        $guard = new PcreLimitGuard();
        $guard->enter();

        try {
            throw new \RuntimeException('build failed mid-pass');
        } catch (\RuntimeException $e) {
            self::assertSame('build failed mid-pass', $e->getMessage());
        } finally {
            $guard->exit();
        }

        self::assertSame('777', ini_get('pcre.backtrack_limit'));
        self::assertSame('666', ini_get('pcre.recursion_limit'));

        ini_set('pcre.backtrack_limit', $originalBacktrack);
        ini_set('pcre.recursion_limit', $originalRecursion);
    }

    public function testExitIsIdempotentAndSafeWithoutEnter(): void
    {
        $guard = new PcreLimitGuard();
        $guard->exit();

        $original = ini_get('pcre.backtrack_limit');
        self::assertIsString($original);

        $guard = new PcreLimitGuard();
        $guard->enter();
        $guard->exit();
        $guard->exit();

        self::assertSame($original, ini_get('pcre.backtrack_limit'));
    }

    public function testEnterIsIdempotent(): void
    {
        $originalBacktrack = ini_get('pcre.backtrack_limit');
        self::assertIsString($originalBacktrack);

        $guard = new PcreLimitGuard();
        $guard->enter();
        $guard->enter();

        try {
            self::assertSame((string) ExcludePattern::BACKTRACK_LIMIT, ini_get('pcre.backtrack_limit'));
        } finally {
            $guard->exit();
        }

        self::assertSame($originalBacktrack, ini_get('pcre.backtrack_limit'));
    }
}
