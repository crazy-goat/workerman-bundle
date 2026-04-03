<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

final class ServerStopFailedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Workerman stop failed (timeout).');
    }
}
