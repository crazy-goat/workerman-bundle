<?php

declare(strict_types=1);

use CrazyGoat\WorkermanBundle\Test\App\Kernel;

require_once dirname(__DIR__, 2) . '/vendor/autoload_runtime.php';

return fn(array $context): Kernel => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
