<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\FileMonitorWatcher;

use CrazyGoat\WorkermanBundle\Utils;
use Workerman\Worker;

abstract class FileMonitorWatcher
{
    /** @var string[] */
    protected readonly array $sourceDir;

    /** Compiled regex pattern (empty string when no patterns) */
    private readonly string $filePatternRegex;

    /**
     * @param string[] $sourceDir
     * @param string[] $filePattern
     */
    public static function create(Worker $worker, array $sourceDir, array $filePattern): self
    {
        return \extension_loaded('inotify')
            ? new InotifyMonitorWatcher($worker, $sourceDir, $filePattern)
            : new PollingMonitorWatcher($worker, $sourceDir, $filePattern)
        ;
    }

    /**
     * @param string[] $sourceDir
     * @param string[] $filePattern
     */
    protected function __construct(protected readonly Worker $worker, array $sourceDir, array $filePattern)
    {
        $this->sourceDir = array_filter($sourceDir, is_dir(...));
        $this->filePatternRegex = $this->compilePatterns($filePattern);
    }

    abstract public function start(): void;

    final protected function checkPattern(string $filename): bool
    {
        if ($this->filePatternRegex === '') {
            return false;
        }

        $result = \preg_match($this->filePatternRegex, $filename);
        if ($result === false) {
            throw new \RuntimeException(\preg_last_error_msg());
        }

        return $result === 1;
    }

    /**
     * @param string[] $patterns
     */
    private function compilePatterns(array $patterns): string
    {
        if ($patterns === []) {
            return '';
        }

        $regexes = \array_map($this->globToRegex(...), $patterns);

        return '/^(?:' . \implode('|', $regexes) . ')$/D';
    }

    private function globToRegex(string $glob): string
    {
        $regex = '';
        $i = 0;
        $length = \strlen($glob);

        while ($i < $length) {
            $char = $glob[$i];

            switch ($char) {
                case '\\':
                    if ($i + 1 < $length) {
                        $regex .= \preg_quote($glob[$i + 1], '/');
                        $i++;
                    } else {
                        $regex .= '(?!)';
                    }
                    break;

                case '*':
                    $regex .= '.*';
                    break;

                case '?':
                    $regex .= '.';
                    break;

                case '[':
                    $class = '';
                    $j = $i + 1;

                    if ($j < $length && $glob[$j] === '!') {
                        $class .= '^';
                        $j++;
                    }

                    if ($j < $length && $glob[$j] === ']') {
                        $class .= ']';
                        $j++;
                    }

                    while ($j < $length && $glob[$j] !== ']') {
                        $class .= $glob[$j] === '-' ? '-' : \preg_quote($glob[$j], '/');
                        $j++;
                    }

                    if ($j < $length) {
                        $regex .= '[' . $class . ']';
                        $i = $j;
                    } else {
                        $regex .= \preg_quote($char, '/');
                    }
                    break;

                default:
                    $regex .= \preg_quote($char, '/');
            }

            $i++;
        }

        return $regex;
    }

    /**
     * Create a recursive directory iterator with the given flags and mode.
     *
     * Both PollingMonitorWatcher and InotifyMonitorWatcher need this same
     * boilerplate; centralising it here means traversal behaviour (skip
     * dot-dirs, follow symlinks, etc.) can be changed in one place.
     *
     * @param int<0, max> $flags FilesystemIterator flags (e.g. SKIP_DOTS | UNIX_PATHS)
     * @param 0|1|2       $mode  RecursiveIteratorIterator mode (LEAVES_ONLY=0, SELF_FIRST=1, CHILD_FIRST=2)
     *
     * @return \RecursiveIteratorIterator<\RecursiveDirectoryIterator>
     */
    protected function createRecursiveIterator(string $dir, int $flags, int $mode): \RecursiveIteratorIterator
    {
        return new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, $flags),
            $mode,
        );
    }

    final protected function reload(): void
    {
        Utils::reload(reloadAllWorkers: true);
    }
}
