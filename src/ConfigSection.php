<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

enum ConfigSection: string
{
    case WORKERMAN = 'workerman';
    case PROCESS = 'process';
    case SCHEDULER = 'scheduler';
}
