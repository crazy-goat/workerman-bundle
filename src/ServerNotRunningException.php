<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException as BaseServerNotRunningException;

/**
 * @deprecated Use CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException instead
 */
final class ServerNotRunningException extends BaseServerNotRunningException
{
    public function __construct()
    {
        trigger_deprecation(
            'crazy-goat/workerman-bundle',
            '2.1',
            'The "%s" class is deprecated, use "%s" instead.',
            self::class,
            BaseServerNotRunningException::class,
        );

        BaseServerNotRunningException::__construct();
    }
}
