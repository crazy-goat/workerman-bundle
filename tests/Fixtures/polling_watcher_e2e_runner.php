<?php

declare(strict_types=1);

/**
 * E2E test runner for PollingMonitorWatcher.
 *
 * Usage: php polling_watcher_e2e_runner.php <temp_dir> <autoload_path>
 *
 * Creates a PollingMonitorWatcher in a subprocess and verifies:
 * 1. The watcher can be instantiated with a real Worker
 * 2. checkFileSystemChanges runs without error
 * 3. No false positive detection before file changes
 *
 * File change detection triggering reload() is intentionally not tested
 * here because Utils::reload(reloadAllWorkers: true) sends SIGUSR1 to
 * the parent process via posix_getppid(), which would kill PHPUnit.
 * The detection logic is verified in the unit tests.
 */

$tempDir = $argv[1] ?? '';
$autoloadPath = $argv[2] ?? '';

if ($tempDir === '' || $autoloadPath === '' || !is_dir($tempDir)) {
    fprintf(STDERR, "Usage: php polling_watcher_e2e_runner.php <temp_dir> <autoload_path>\n");
    exit(1);
}

require $autoloadPath;

use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\PollingMonitorWatcher;

$watchedFile = $tempDir . '/app.php';
file_put_contents($watchedFile, '<?php // v1');
touch($watchedFile, time() - 10);

$worker = new Workerman\Worker();
$worker->name = 'e2e-test';

$watcherClass = new ReflectionClass(PollingMonitorWatcher::class);
$watcher = $watcherClass->newInstanceWithoutConstructor();

$parentClass = $watcherClass->getParentClass();
if (!$parentClass instanceof ReflectionClass) {
    fprintf(STDERR, "FAIL: Cannot get parent class reflection\n");
    exit(4);
}

$workerProp = $parentClass->getProperty('worker');
$workerProp->setValue($watcher, $worker);

$sourceDirProp = $parentClass->getProperty('sourceDir');
$sourceDirProp->setValue($watcher, [$tempDir]);

$filePatternProp = $parentClass->getProperty('filePattern');
$filePatternProp->setValue($watcher, ['*.php']);

$lastMTimeProp = $watcherClass->getProperty('lastMTime');
$expectedBefore = time() - 5;
$lastMTimeProp->setValue($watcher, $expectedBefore);

// Run checkFileSystemChanges — with no file changes, this should NOT
// call reload() (which would send SIGUSR1 to parent).
$checkMethod = $watcherClass->getMethod('checkFileSystemChanges');
$checkMethod->invoke($watcher);

$afterMTime = $lastMTimeProp->getValue($watcher);

if ($afterMTime !== $expectedBefore) {
    fprintf(STDERR, "FAIL: lastMTime changed without file modification (was %d, now %d)\n", $expectedBefore, $afterMTime);
    exit(2);
}

exit(0);
