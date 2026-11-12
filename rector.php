<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/benchmarks', __DIR__ . '/bin', __DIR__ . '/tools',])
    ->withBootstrapFiles([__DIR__ . '/tools/php-cs-fixer/autoload.php'])
    ->withPhpSets(php82: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true, symfonyCodeQuality: true);
