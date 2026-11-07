<?php

declare(strict_types=1);

use CrazyGoat\WorkermanBundle\Runtime;
use CrazyGoat\WorkermanBundle\Test\App\Kernel;
use CrazyGoat\WorkermanBundle\Test\App\TestPorts;

$_SERVER['APP_RUNTIME'] = Runtime::class;

require_once dirname(__DIR__, 2) . '/vendor/autoload_runtime.php';

return function (array $context): Kernel {
    TestPorts::applyCacheDir(dirname(__DIR__, 2));

    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
