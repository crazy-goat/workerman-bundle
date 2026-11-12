<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Phar;

use PHPUnit\Framework\TestCase;

final class PharReadOnlyGuardTest extends TestCase
{
    public function testPharReadOnlyIsDisabled(): void
    {
        $pharReadOnly = (bool) ini_get('phar.readonly');

        if (!$pharReadOnly) {
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
