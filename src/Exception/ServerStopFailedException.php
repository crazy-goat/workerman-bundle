<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Exception thrown when server stop operation fails (timeout).
 */
final class ServerStopFailedException extends ServerException
{
    public function __construct()
    {
        parent::__construct('Workerman stop failed (timeout).');
    }
}
