<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Exception thrown when attempting to interact with a server that is not running.
 */
final class ServerNotRunningException extends ServerException
{
    public function __construct()
    {
        parent::__construct('Workerman is not running.');
    }
}
