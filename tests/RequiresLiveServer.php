<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

/**
 * Live-server checks that must not skip silently in CI.
 *
 * The test daemon is started by phpunit's bootstrap. If it is not running,
 * a live-server test normally skips so the suite still works for a targeted
 * local run. CI sets WORKERMAN_REQUIRE_LIVE_SERVER=1, which turns the same
 * condition into a failure instead (issue #925).
 */
trait RequiresLiveServer
{
    private static function failLiveServer(string $message): never
    {
        if (getenv('WORKERMAN_REQUIRE_LIVE_SERVER') === '1') {
            self::fail($message);
        }

        self::markTestSkipped($message);
    }
}
