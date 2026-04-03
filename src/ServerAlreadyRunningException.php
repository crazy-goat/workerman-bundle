<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

final class ServerAlreadyRunningException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Workerman is already running.');
    }
}
