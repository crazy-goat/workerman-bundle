#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Integration test: builds a PHAR and verifies its structure.
 * Run with: composer test:build:phar
 *
 * Note: This builds a PHAR of the bundle itself. The test kernel
 * (CrazyGoat\WorkermanBundle\Test\App\Kernel) lives in tests/ which
 * is excluded from the PHAR, so we can't run the PHAR as a CLI app here.
 * Instead we verify the PHAR structure and contents.
 */

use CrazyGoat\WorkermanBundle\Test\App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

require_once __DIR__ . '/../vendor/autoload.php';

$exitCode = 0;
$fileList = [];

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
        '--filename' => 'test-app.phar',
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
    $pharSize = filesize($pharPath);
    echo "   PHAR created: {$pharPath} (" . number_format($pharSize) . " bytes)\n";

    // 2. Verify PHAR structure
    echo "2) Verifying PHAR structure...\n";
    $phar = new \Phar($pharPath);
    $fileList = [];
    foreach (new \RecursiveIteratorIterator($phar) as $file) {
        $fileList[] = $file->getPathname();
    }

    $checks = [
        'vendor/autoload.php' => false,
        'src/Command/BuildPharCommand.php' => false,
        'src/PharHelper.php' => false,
        'src/Runner.php' => false,
    ];

    foreach ($fileList as $path) {
        $relativePath = substr($path, strlen('phar://' . $pharPath . '/'));
        if (isset($checks[$relativePath])) {
            $checks[$relativePath] = true;
        }
    }

    $allOk = true;
    foreach ($checks as $file => $found) {
        $status = $found ? 'OK' : 'MISSING';
        if (!$found) { $allOk = false; }
        echo "   {$file}: {$status}\n";
    }

    if (!$allOk) {
        throw new \RuntimeException('PHAR is missing required files');
    }

    // 3. Check that excluded files are NOT in the PHAR
    echo "3) Checking exclusions...\n";
    $excludedChecks = [
        '.git/' => 'MUST_NOT_EXIST',
        'tests/App/Kernel.php' => 'MUST_NOT_EXIST',
        'var/cache/' => 'MUST_NOT_EXIST',
        'docs/' => 'MUST_NOT_EXIST',
    ];

    foreach ($fileList as $path) {
        $relativePath = substr($path, strlen('phar://' . $pharPath . '/'));
        foreach ($excludedChecks as $pattern => &$status) {
            if ($status === 'MUST_NOT_EXIST' && str_starts_with($relativePath, $pattern)) {
                $status = 'FOUND (should be excluded!)';
                $allOk = false;
            }
        }
    }
    unset($status);

    foreach ($excludedChecks as $pattern => $status) {
        $label = $status === 'MUST_NOT_EXIST' ? 'OK (excluded)' : $status;
        echo "   {$pattern}: {$label}\n";
    }

    // 4. Check file count is reasonable
    echo "4) Checking file count...\n";
    $totalFiles = count($fileList);
    echo "   Total files: {$totalFiles}\n";
    if ($totalFiles < 1000) {
        throw new \RuntimeException("PHAR seems too small: {$totalFiles} files (expected > 1000)");
    }

    // 5. Verify the stub has expected structure
    echo "5) Verifying stub structure...\n";
    $phar = new \Phar($pharPath);
    $stub = $phar->getStub();
    $stubChecks = [
        '#!/usr/bin/env php' => false,
        "define('IN_PHAR', true)" => false,
        'Phar::mapPhar' => false,
        'APP_RUNTIME' => false,
        'APP_CACHE_DIR' => false,
        'APP_LOG_DIR' => false,
        'vendor/autoload.php' => false,
        'Console\\Application' => false,
        '__HALT_COMPILER' => false,
    ];
    foreach ($stubChecks as $pattern => &$found) {
        if (str_contains($stub, $pattern)) {
            $found = true;
        }
    }
    unset($found);
    foreach ($stubChecks as $pattern => $found) {
        $status = $found ? 'OK' : 'MISSING';
        if (!$found) { $allOk = false; }
        echo "   Stub contains '{$pattern}': {$status}\n";
    }

    if ($allOk) {
        echo "\n=== PHAR Build Integration Test: PASSED ===\n";
    } else {
        throw new \RuntimeException('Some checks failed');
    }

} catch (\Throwable $e) {
    echo "\n!!! FAILED: {$e->getMessage()}\n";
    $exitCode = 1;
} finally {
    // Cleanup
    if (isset($buildDir) && is_dir($buildDir)) {
        $it = new \RecursiveDirectoryIterator($buildDir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iter = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iter as $f) {
            if ($f->isDir()) { rmdir($f->getRealPath()); }
            else { unlink($f->getRealPath()); }
        }
        rmdir($buildDir);
    }
}

exit($exitCode);
