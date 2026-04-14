<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Command;

enum ServerAction: string
{
    case Start = 'start';
    case Stop = 'stop';
    case Restart = 'restart';
    case Reload = 'reload';
    case Status = 'status';
    case Connections = 'connections';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

