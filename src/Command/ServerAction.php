<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Command;

enum ServerAction: string
{
    case START = 'start';
    case STOP = 'stop';
    case RESTART = 'restart';
    case RELOAD = 'reload';
    case STATUS = 'status';
    case CONNECTIONS = 'connections';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

