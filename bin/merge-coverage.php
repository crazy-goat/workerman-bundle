<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover;

require __DIR__ . '/../vendor/autoload.php';

$argc = $_SERVER['argc'] ?? 0;
$argv = $_SERVER['argv'] ?? [];

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php bin/merge-coverage.php <main.cov> <subprocess-dir> <clover.xml>\n");
    exit(2);
}

[, $mainFile, $subprocessDirectory, $cloverFile] = $argv;

if (!is_readable($mainFile)) {
    fwrite(STDERR, sprintf("Main coverage file not readable: %s\n", $mainFile));
    exit(2);
}

$main = require $mainFile;
if (!$main instanceof CodeCoverage) {
    fwrite(STDERR, sprintf("Main coverage file did not return CodeCoverage: %s\n", $mainFile));
    exit(2);
}

$files = is_dir($subprocessDirectory) ? glob($subprocessDirectory . '/*.cov') : [];
if (!is_array($files)) {
    $files = [];
}

$merged = 0;
foreach ($files as $file) {
    $owner = fileowner($file);
    $permissions = fileperms($file);
    if (($owner !== false && function_exists('posix_geteuid') && $owner !== posix_geteuid())
        || ($permissions !== false && ($permissions & 0022) !== 0)) {
        fwrite(STDERR, sprintf("Skipping coverage file with unsafe ownership or permissions: %s\n", $file));
        continue;
    }

    try {
        $coverage = require $file;
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("Skipping unreadable coverage file %s: %s\n", $file, $e->getMessage()));
        continue;
    }

    if (!$coverage instanceof CodeCoverage) {
        fwrite(STDERR, sprintf("Skipping coverage file that did not return CodeCoverage: %s\n", $file));
        continue;
    }

    try {
        $main->merge($coverage);
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("Skipping incompatible coverage file %s: %s\n", $file, $e->getMessage()));
        continue;
    }

    ++$merged;
}

(new Clover())->process($main, $cloverFile);

printf(
    "Merged %d subprocess coverage file(s) into %s\n",
    $merged,
    $cloverFile,
);
