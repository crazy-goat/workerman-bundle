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
 *   3 — unexpected error, including a reload-signal count other than one
 *
 * Reload-signal containment (#1126): when the watcher detects the change it
 * calls Utils::reload(true), which sends SIGUSR1 to the parent process. The
 * old code only ignored SIGUSR1 in this process, so the signal still hit
 * this script's parent (PHPUnit, or any shell running the fixture) and
 * could terminate it. The sweep therefore runs in a forked child: the
 * reload signal lands on this supervisor process, which counts it and
 * fails unless exactly one arrives, and the test runner never sees it.
 * Without pcntl the sweep runs inline, as before.
 */

use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\FileMonitorWatcher;
use CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher\PollingMonitorWatcher;

$tempDir = $argv[1] ?? '';
$autoloadPath = $argv[2] ?? '';
$tail = ($argv[3] ?? '') === 'tail';

if ($tempDir === '' || $autoloadPath === '' || !is_dir($tempDir)) {
    fprintf(STDERR, "Usage: php polling_watcher_mid_sweep_runner.php <temp_dir> <autoload_path>\n");
    exit(1);
}

/**
 * Runs the mid-sweep scenario and returns the process exit code.
 */
function runMidSweepCheck(string $tempDir, string $autoloadPath, bool $tail): int
{
    require $autoloadPath;

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

        return 3;
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

        return 3;
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

        return 3;
    }

    if (!$tail) {
        // The remaining part of the old sweep must not revisit the prefix.
        $checkMethod->invoke($watcher);
        if ($lastMTimeProp->getValue($watcher) !== $initialMTime) {
            fprintf(STDERR, "FAIL: Modified prefix was detected before the next sweep\n");

            return 2;
        }
    }
    $iterators = $iteratorsProp->getValue($watcher);
    if (!empty($iterators)) {
        fprintf(STDERR, "FAIL: Sweep did not complete after 2 ticks\n");

        return 3;
    }

    // A whole new sweep takes two ticks; the changed file can be in either one.
    $checkMethod->invoke($watcher);
    if ($tail && $lastMTimeProp->getValue($watcher) !== $initialMTime) {
        fprintf(STDERR, "FAIL: Tail file was detected in the first tick\n");

        return 2;
    }
    if ($lastMTimeProp->getValue($watcher) === $initialMTime) {
        $checkMethod->invoke($watcher);
    }
    if ($lastMTimeProp->getValue($watcher) !== $modifiedMTime) {
        fprintf(STDERR, "FAIL: Modified file was not detected during the next sweep\n");

        return 2;
    }

    return 0;
}

if (function_exists('pcntl_fork') && defined('SIGUSR1')) {
    // Supervisor: count the reload signals the sweep sends to its parent
    // (this process). The child resets the disposition to SIG_IGN so a
    // stray signal cannot kill it mid-sweep.
    $reloadSignals = 0;
    pcntl_async_signals(true);
    pcntl_signal(SIGUSR1, static function () use (&$reloadSignals): void {
        $reloadSignals++;
    });

    $childPid = pcntl_fork();
    if ($childPid === -1) {
        fprintf(STDERR, "FAIL: Could not fork the sweep supervisor\n");
        exit(3);
    }

    if ($childPid === 0) {
        pcntl_signal(SIGUSR1, SIG_IGN);
        exit(runMidSweepCheck($tempDir, $autoloadPath, $tail));
    }

    $status = 0;
    while (true) {
        $waited = pcntl_waitpid($childPid, $status, WNOHANG);
        if ($waited === $childPid) {
            break;
        }
        if ($waited === -1) {
            fprintf(STDERR, "FAIL: Could not wait for the sweep child\n");
            exit(3);
        }
        pcntl_signal_dispatch();
        usleep(5000);
    }

    if (!pcntl_wifexited($status)) {
        fprintf(STDERR, "FAIL: Sweep child did not exit normally\n");
        exit(3);
    }

    $childExit = pcntl_wexitstatus($status);
    if ($childExit !== 0) {
        exit($childExit);
    }

    // Exactly one file changes, so exactly one reload signal is expected.
    if ($reloadSignals !== 1) {
        fprintf(STDERR, "FAIL: Expected exactly one reload signal (SIGUSR1), got %d\n", $reloadSignals);
        exit(3);
    }

    exit(0);
}

// No pcntl: run inline, as before.
exit(runMidSweepCheck($tempDir, $autoloadPath, $tail));
