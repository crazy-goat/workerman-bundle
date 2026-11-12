<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http;

final class ConnectionIntent
{
    public static function shouldClose(Request $request): bool
    {
        return $request->protocolVersion() === '1.0'
            || strcasecmp((string) $request->header('Connection', ''), 'close') === 0;
    }
}
