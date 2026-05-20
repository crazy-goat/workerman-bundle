#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Integration test: builds a PHAR and verifies its structure.
 * Run with: php -d phar.readonly=0 tests/build-phar-test.php
 */

use CrazyGoat\WorkermanBundle\Test\App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

require_once __DIR__ . '/../vendor/autoload.php';

$exitCode = 0;

try {
    echo "=== PHAR Build Integration Test ===\n\n";

    // 1. Build PHAR
    echo "1) Building PHAR...\n";
    $buildDir = sys_get_temp_dir() . '/workerman-phar-test-' . uniqid();
    $pharPath = $buildDir . '/test-app.phar';

    $kernel = new Kernel('test', true);
    $kernel->boot();
    $application = new Application($kernel);
    $application->setAutoExit(false);

    $input = new ArrayInput([
        'command' => 'workerman:build:phar',
        '--output-dir' => $buildDir,
        '--kernel-class' => 'CrazyGoat\WorkermanBundle\Test\App\Kernel',
    ]);
    $input->setInteractive(false);

    $output = new NullOutput();
    $result = $application->run($input, $output);

    if ($result !== 0) {
        throw new \RuntimeException('PHAR build command failed with exit code ' . $result);
    }
    if (!file_exists($pharPath)) {
        throw new \RuntimeException('PHAR file not created at ' . $pharPath);
    }
    echo "   PHAR created: {$pharPath}\n";

    // 2. Verify PHAR structure
    echo "2) Verifying PHAR structure...\n";
    $phar = new \Phar($pharPath);
    $files = [];
    foreach (new \RecursiveIteratorIterator($phar) as $file) {
        $files[] = $file->getPathname();
    }

    $checks = [
        'vendor/autoload.php' => false,
        'src/Command/BuildPharCommand.php' => false,
        'src/PharHelper.php' => false,
    ];

    foreach ($files as $path) {
        $pharPathOnly = substr($path, strlen('phar://' . $pharPath . '/'));
        if (isset($checks[$pharPathOnly])) {
            $checks[$pharPathOnly] = true;
        }
    }

    $allOk = true;
    foreach ($checks as $file => $found) {
        $status = $found ? 'OK' : 'MISSING';
        if (!$found) $allOk = false;
        echo "   {$file}: {$status}\n";
    }

    if (!$allOk) {
        throw new \RuntimeException('PHAR is missing required files');
    }

    // 3. Verify autoloader works from within the PHAR
    echo "3) Verifying autoloader from PHAR...\n";
    $phar->offsetGet('vendor/autoload.php'); // just check it exists
    $autoloadPath = 'phar://' . $pharPath . '/vendor/autoload.php';
    require $autoloadPath;
    if (!class_exists(\Symfony\Component\HttpKernel\Kernel::class)) {
        throw new \RuntimeException('Symfony Kernel class not loadable from PHAR');
    }
    echo "   Symfony Kernel: " . \Symfony\Component\HttpKernel\Kernel::VERSION . " - OK\n";

    // 4. Check file count is reasonable
    echo "4) Checking file count...\n";
    $count = count($files);
    echo "   Total files: {$count}\n";
    if ($count < 1000) {
        throw new \RuntimeException("PHAR seems too small: {$count} files (expected > 1000)");
    }

    echo "\n=== PHAR Build Integration Test: PASSED ===\n";

} catch (\Throwable $e) {
    echo "\n!!! FAILED: {$e->getMessage()}\n";
    $exitCode = 1;
} finally {
    // Cleanup
    if (isset($buildDir) && is_dir($buildDir)) {
        $it = new \RecursiveDirectoryIterator($buildDir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) rmdir($file->getRealPath());
            else unlink($file->getRealPath());
        }
        rmdir($buildDir);
    }
}

exit($exitCode);
