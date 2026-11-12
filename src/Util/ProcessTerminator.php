<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Util;

/**
 * @see docs/troubleshooting.md
 */
final class ProcessTerminator
{
    public static function terminate(int $code, ?bool $hardExit = null): never
    {
        if ($hardExit ?? \extension_loaded('grpc')) {
            \posix_kill(\posix_getpid(), \SIGKILL);

            exit($code);
        }

        exit($code);
    }
}
