<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

final class ServerAlreadyRunningException extends ServerException
{
    public function __construct()
    {
        parent::__construct('Workerman is already running.');
    }
}
