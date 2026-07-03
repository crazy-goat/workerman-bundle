<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Phar;

use PHPUnit\Framework\TestCase;

/**
 * Guard test that ensures PHAR-build tests are never silently skipped
 * due to `phar.readonly` being enabled.
 *
 * In CI (no WORKERMAN_ALLOW_PHAR_READONLY_SKIP env var), this test fails
 * if `phar.readonly` is set — catching misconfigured CI runners before
 * they hide PHAR regressions behind a green build.
 *
 * Developers who cannot disable `phar.readonly` locally (e.g. shared
 * hosting, restricted Docker images) can opt out by setting:
 *
 *   WORKERMAN_ALLOW_PHAR_READONLY_SKIP=1
 */
final class PharReadOnlyGuardTest extends TestCase
{
    public function testPharReadOnlyIsDisabled(): void
    {
        $pharReadOnly = (bool) ini_get('phar.readonly');

        if (!$pharReadOnly) {
            // phar.readonly is Off — PHAR tests will execute normally.
            $this->addToAssertionCount(1);

            return;
        }

        $allowSkip = getenv('WORKERMAN_ALLOW_PHAR_READONLY_SKIP');
        if ($allowSkip !== false && $allowSkip !== '') {
            self::markTestSkipped(
                'phar.readonly is On but WORKERMAN_ALLOW_PHAR_READONLY_SKIP is set — skipping guard.',
            );
        }

        self::fail(
            'phar.readonly is enabled in this PHP runtime. '
            . 'PHAR-build tests will be silently skipped, hiding potential regressions. '
            . 'Fix: add phar.readonly=0 to php.ini or the PHPUnit command. '
            . 'Developer opt-out: set WORKERMAN_ALLOW_PHAR_READONLY_SKIP=1.',
        );
    }
}
