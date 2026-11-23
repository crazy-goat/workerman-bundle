<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

\CrazyGoat\WorkermanBundle\Test\App\SubprocessCoverage::start();

\CrazyGoat\WorkermanBundle\Util\Wait::until(static fn(): bool => true, 1);

echo "covered\n";
