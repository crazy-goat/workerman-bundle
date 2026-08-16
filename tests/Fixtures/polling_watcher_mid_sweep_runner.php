<?php

declare(strict_types=1);

/**
 * E2E runner: verifies that a file modified in an already-scanned region
 * (the prefix of the tree that was visited in earlier ticks) is detected
 * on the next sweep.
 *
 * Usage: php polling_watcher_mid_sweep_runner.php <temp_dir> <autoload_path>
 *
 * Exit codes:
 *   0 — success (detection occurred on the next sweep)
 *   1 — usage error
 *   2 — detection failed (lastMTime not updated after modifying a file
 *       in the already-scanned region)
 *   3 — unexpected error
 */

$tempDir = $argv[1] ?? '';
$autoloadPath = $argv[2] ?? '';

if ($tempDir === '' || $autoloadPath === '' || !is_dir($tempDir)) {
    fprintf(STDERR, "Usage: php polling_watcher_mid_sweep_runner.php <temp_dir> <autoload_path>\n");
    exit(1);
}

// Ignore SIGUSR1 so Utils::reload() does not kill our parent (PHPUnit).
if (function_exists('pcntl_async_signals') && defined('SIGUSR1')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGUSR1, SIG_IGN);
}

require $autoloadPath;

use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\FileMonitorWatcher;
use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\PollingMonitorWatcher;

// Build a tree spanning two ticks: 600 files at MAX_FILES_PER_TICK=500/tick.
for ($i = 0; $i < 600; $i++) {
    file_put_contents($tempDir . '/file' . $i . '.php', '<?php');
}
clearstatcache();

$worker = new Workerman\Worker();
$worker->name = 'mid-sweep-test';

$watcherClass = new ReflectionClass(PollingMonitorWatcher::class);
$watcher = $watcherClass->newInstanceWithoutConstructor();

$parentClass = $watcherClass->getParentClass();
if (!$parentClass instanceof ReflectionClass) {
    fprintf(STDERR, "FAIL: Cannot get parent class reflection\n");
    exit(3);
}

$workerProp = $parentClass->getProperty('worker');
$workerProp->setValue($watcher, $worker);

$sourceDirProp = $parentClass->getProperty('sourceDir');
$sourceDirProp->setValue($watcher, [$tempDir]);

$regexProp = $parentClass->getProperty('filePatternRegex');
$compilePatterns = new ReflectionMethod(FileMonitorWatcher::class, 'compilePatterns');
$regexProp->setValue($watcher, $compilePatterns->invoke($watcher, ['*.php']));

$lastMTimeProp = $watcherClass->getProperty('lastMTime');
// Set lastMTime to now so existing files are not seen as modified.
$initialMTime = time();
$lastMTimeProp->setValue($watcher, $initialMTime);

$checkMethod = $watcherClass->getMethod('checkFileSystemChanges');

// Tick 1: processes first 500 files.
$checkMethod->invoke($watcher);

// Tick 2: completes the sweep (remaining 100 files).
$checkMethod->invoke($watcher);

// Verify the sweep completed (no resume state).
$resumeDirsProp = $watcherClass->getProperty('resumeDirs');
$resumeDirs = $resumeDirsProp->getValue($watcher);
if (!empty($resumeDirs)) {
    fprintf(STDERR, "FAIL: Sweep did not complete after 2 ticks (resumeDirs not empty)\n");
    exit(3);
}

// Now modify file0.php (in the already-scanned prefix).
// Ensure the mtime is strictly greater than lastMTime.
sleep(1);
file_put_contents($tempDir . '/file0.php', '<?php // changed');
clearstatcache(true, $tempDir . '/file0.php');

$modifiedMTime = filemtime($tempDir . '/file0.php');
if (!is_int($modifiedMTime) || $modifiedMTime <= $initialMTime) {
    fprintf(STDERR, "FAIL: Modified file mtime (%s) must exceed initial lastMTime (%d)\n", var_export($modifiedMTime, true), $initialMTime);
    exit(3);
}

// Tick 3: starts a new sweep, should detect the change on file0.php.
$checkMethod->invoke($watcher);

$afterMTime = $lastMTimeProp->getValue($watcher);

if ($afterMTime <= $initialMTime) {
    fprintf(STDERR, "FAIL: lastMTime not updated after modifying a file in the already-scanned region (was %d, now %d)\n", $initialMTime, $afterMTime);
    exit(2);
}

exit(0);
