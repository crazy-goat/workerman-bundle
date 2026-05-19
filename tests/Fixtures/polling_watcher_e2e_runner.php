<?php

declare(strict_types=1);

/**
 * E2E test runner for PollingMonitorWatcher.
 *
 * Usage: php polling_watcher_e2e_runner.php <temp_dir> <autoload_path>
 *
 * Creates a PollingMonitorWatcher, monitors a temp directory,
 * triggers a file change, and verifies the detection works.
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
$lastMTimeProp->setValue($watcher, time() - 5);

$checkMethod = $watcherClass->getMethod('checkFileSystemChanges');
$expectedBefore = $lastMTimeProp->getValue($watcher);
$checkMethod->invoke($watcher);

$beforeMTime = $lastMTimeProp->getValue($watcher);

if ($beforeMTime !== $expectedBefore) {
    fprintf(STDERR, "FAIL: lastMTime changed without file modification (was %d, expected %d)\n", $beforeMTime, $expectedBefore);
    exit(2);
}

usleep(1000);
file_put_contents($watchedFile, '<?php // v2');
clearstatcache(true, $watchedFile);

$checkMethod->invoke($watcher);

$afterMTime = $lastMTimeProp->getValue($watcher);

if ($afterMTime <= $expectedBefore) {
    fprintf(STDERR, "FAIL: lastMTime not updated after file change (was %d, now %d)\n", $expectedBefore, $afterMTime);
    exit(3);
}

exit(0);
