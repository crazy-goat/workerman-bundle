<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\PHP;

final class SubprocessCoverage
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started) {
            return;
        }

        $directory = getenv('WMB_SUBPROCESS_COVERAGE_DIR');
        if ($directory === false || $directory === '') {
            return;
        }

        try {
            $filter = new Filter();
            $filter->includeDirectory(\dirname(__DIR__, 2) . '/src');
            $coverage = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
        } catch (\Throwable) {
            return;
        }

        self::$started = true;
        $coverage->start('subprocess-' . \getmypid());

        \register_shutdown_function(static function () use ($coverage, $directory): void {
            if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
                return;
            }
            @\chmod($directory, 0700);

            try {
                $coverage->stop();
                $target = \sprintf('%s/%d-%s.cov', $directory, \getmypid(), \bin2hex(\random_bytes(4)));
                (new PHP())->process($coverage, $target);
                @\chmod($target, 0600);
            } catch (\Throwable) {
            }
        });
    }
}
