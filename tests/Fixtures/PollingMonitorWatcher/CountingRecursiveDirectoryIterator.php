<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Fixtures\PollingMonitorWatcher;

/**
 * RecursiveDirectoryIterator subclass that yields CountingSplFileInfo
 * instances so stat-related calls on each file are observable.
 *
 * `setInfoClass` configures the class returned by `current()`/`getFileInfo()`.
 * The `current()` override is a belt-and-braces guard: if `setInfoClass` is
 * ever bypassed (e.g. by a flag change in the iterator), the assertion
 * surfaces the bug instead of silently returning plain SplFileInfo.
 */
final class CountingRecursiveDirectoryIterator extends \RecursiveDirectoryIterator
{
    public function __construct(string $directory, int $flags)
    {
        parent::__construct($directory, $flags);
        $this->setInfoClass(CountingSplFileInfo::class);
    }

    public function current(): \SplFileInfo
    {
        $info = parent::current();
        \assert($info instanceof CountingSplFileInfo, 'Iterator must yield CountingSplFileInfo instances; check setInfoClass()');

        return $info;
    }
}
