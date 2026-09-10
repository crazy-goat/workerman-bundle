<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Phar;

use CrazyGoat\WorkermanBundle\Phar\ExcludePattern;
use CrazyGoat\WorkermanBundle\Phar\PcreLimitGuard;
use PHPUnit\Framework\TestCase;

/**
 * Covers the per-pass PCRE limit scope guard introduced in issue #568.
 *
 * ExcludePattern::matches() used to raise and restore
 * pcre.backtrack_limit / pcre.recursion_limit on every call. PcreLimitGuard
 * establishes them once for a filtering pass and restores them afterwards —
 * including when the protected block throws (the failing-build case from the
 * acceptance criteria).
 */
final class PcreLimitGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        // Defensive: never leak a lowered limit into another test if a test
        // forgot to exit() the guard.
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

        // Distort the limits so we can prove exit() restores *our* prior
        // values, not just PHP's defaults.
        ini_set('pcre.backtrack_limit', '999');
        ini_set('pcre.recursion_limit', '888');

        $guard = new PcreLimitGuard();
        $guard->enter();
        $guard->exit();

        self::assertSame('999', ini_get('pcre.backtrack_limit'));
        self::assertSame('888', ini_get('pcre.recursion_limit'));

        // Restore the originals for any later test in the process.
        ini_set('pcre.backtrack_limit', $originalBacktrack);
        ini_set('pcre.recursion_limit', $originalRecursion);
    }

    public function testExitRestoresEvenWhenProtectedBlockThrows(): void
    {
        $originalBacktrack = ini_get('pcre.backtrack_limit');
        $originalRecursion = ini_get('pcre.recursion_limit');
        self::assertIsString($originalBacktrack);
        self::assertIsString($originalRecursion);

        // Distort first so the test proves restore-to-prior, not restore-to-default.
        ini_set('pcre.backtrack_limit', '777');
        ini_set('pcre.recursion_limit', '666');

        $guard = new PcreLimitGuard();
        $guard->enter();

        try {
            throw new \RuntimeException('build failed mid-pass');
        } catch (\RuntimeException $e) {
            // The protected block threw; the guard's finally must still
            // have restored the limit.
            self::assertSame('build failed mid-pass', $e->getMessage());
        } finally {
            $guard->exit();
        }

        // The acceptance criterion: limits restored after the pass even on
        // throw (restored to the distorted prior, not the test-start original).
        self::assertSame('777', ini_get('pcre.backtrack_limit'));
        self::assertSame('666', ini_get('pcre.recursion_limit'));

        ini_set('pcre.backtrack_limit', $originalBacktrack);
        ini_set('pcre.recursion_limit', $originalRecursion);
    }

    public function testExitIsIdempotentAndSafeWithoutEnter(): void
    {
        // Calling exit() without a prior enter() must be a safe no-op.
        $guard = new PcreLimitGuard();
        $guard->exit();

        $original = ini_get('pcre.backtrack_limit');
        self::assertIsString($original);

        // A second exit() after a real enter()/exit() cycle is also a no-op.
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
        $guard->enter(); // second enter must not overwrite the captured prior

        try {
            self::assertSame((string) ExcludePattern::BACKTRACK_LIMIT, ini_get('pcre.backtrack_limit'));
        } finally {
            $guard->exit();
        }

        self::assertSame($originalBacktrack, ini_get('pcre.backtrack_limit'));
    }
}
