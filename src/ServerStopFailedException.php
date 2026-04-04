<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use CrazyGoat\WorkermanBundle\Exception\ServerStopFailedException as BaseServerStopFailedException;

/**
 * @deprecated Use CrazyGoat\WorkermanBundle\Exception\ServerStopFailedException instead
 */
final class ServerStopFailedException extends BaseServerStopFailedException
{
    public function __construct()
    {
        trigger_deprecation(
            'crazy-goat/workerman-bundle',
            '2.1',
            'The "%s" class is deprecated, use "%s" instead.',
            self::class,
            BaseServerStopFailedException::class,
        );

        BaseServerStopFailedException::__construct();
    }
}
