<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use CrazyGoat\WorkermanBundle\Exception\ServerAlreadyRunningException as BaseServerAlreadyRunningException;

/**
 * @deprecated Use CrazyGoat\WorkermanBundle\Exception\ServerAlreadyRunningException instead
 */
final class ServerAlreadyRunningException extends BaseServerAlreadyRunningException
{
    public function __construct()
    {
        trigger_deprecation(
            'crazy-goat/workerman-bundle',
            '2.1',
            'The "%s" class is deprecated, use "%s" instead.',
            self::class,
            BaseServerAlreadyRunningException::class,
        );

        BaseServerAlreadyRunningException::__construct();
    }
}
