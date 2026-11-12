<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

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
