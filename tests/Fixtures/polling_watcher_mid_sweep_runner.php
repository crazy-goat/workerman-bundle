<?php

declare(strict_types=1);

/**
 * E2E runner: verifies that a file modified in an already-scanned region
 * (the prefix of the tree that was visited in earlier ticks) is detected
 * on the next sweep.
 *
 * Usage: php polling_watcher_mid_sweep_runner.php <temp_dir> <autoload_path> [tail]
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
$tail = ($argv[3] ?? '') === 'tail';

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

// Build a tree spanning two ticks: 600 files at DEFAULT_MAX_FILES_PER_TICK=500/tick.
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

// Promoted readonly polling tuning is initialized by the constructor; this
// fixture bypasses it, so set the defaults explicitly.
$watcherClass->getProperty('pollingInterval')->setValue($watcher, FileMonitorWatcher::DEFAULT_POLLING_INTERVAL);
$watcherClass->getProperty('maxFilesPerTick')->setValue($watcher, FileMonitorWatcher::DEFAULT_MAX_FILES_PER_TICK);

$checkMethod = $watcherClass->getMethod('checkFileSystemChanges');

// Select from the actual traversal order, not from file names or creation order.
$entries = iterator_to_array(new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tempDir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
    RecursiveIteratorIterator::LEAVES_ONLY,
), false);
$target = $entries[$tail ? 599 : 0]->getPathname();
$iteratorsProp = $watcherClass->getProperty('iterators');

// Tick 1 visits the prefix and leaves the sweep in progress.
$checkMethod->invoke($watcher);
$iterators = $iteratorsProp->getValue($watcher);
if (empty($iterators)) {
    fprintf(STDERR, "FAIL: Sweep completed before the second tick\n");
    exit(3);
}

if ($tail) {
    // Exercise detection at the end of the next sweep as well.
    $checkMethod->invoke($watcher);
}

// In the prefix case this modification happens between ticks, after the
// watcher visited the file. The mtime must exceed its whole-second watermark.
sleep(1);
file_put_contents($target, '<?php // changed');
clearstatcache(true, $target);
$modifiedMTime = filemtime($target);
if (!is_int($modifiedMTime) || $modifiedMTime <= $initialMTime) {
    fprintf(STDERR, "FAIL: Modified file mtime (%s) must exceed initial lastMTime (%d)\n", var_export($modifiedMTime, true), $initialMTime);
    exit(3);
}

if (!$tail) {
    // The remaining part of the old sweep must not revisit the prefix.
    $checkMethod->invoke($watcher);
    if ($lastMTimeProp->getValue($watcher) !== $initialMTime) {
        fprintf(STDERR, "FAIL: Modified prefix was detected before the next sweep\n");
        exit(2);
    }
}
$iterators = $iteratorsProp->getValue($watcher);
if (!empty($iterators)) {
    fprintf(STDERR, "FAIL: Sweep did not complete after 2 ticks\n");
    exit(3);
}

// A whole new sweep takes two ticks; the changed file can be in either one.
$checkMethod->invoke($watcher);
if ($tail && $lastMTimeProp->getValue($watcher) !== $initialMTime) {
    fprintf(STDERR, "FAIL: Tail file was detected in the first tick\n");
    exit(2);
}
if ($lastMTimeProp->getValue($watcher) === $initialMTime) {
    $checkMethod->invoke($watcher);
}
if ($lastMTimeProp->getValue($watcher) !== $modifiedMTime) {
    fprintf(STDERR, "FAIL: Modified file was not detected during the next sweep\n");
    exit(2);
}

exit(0);
