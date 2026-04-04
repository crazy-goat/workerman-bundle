<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Exception thrown when attempting to start a server that is already running.
 */
final class ServerAlreadyRunningException extends ServerException
{
    public function __construct()
    {
        parent::__construct('Workerman is already running.');
    }
}
