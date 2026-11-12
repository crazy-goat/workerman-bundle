<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Fixtures\PollingMonitorWatcher;

final class CountingRecursiveDirectoryIterator extends \RecursiveDirectoryIterator
{
    public static int $advanceCount = 0;

    public function __construct(string $directory, int $flags)
    {
        parent::__construct($directory, $flags);
        $this->setInfoClass(CountingSplFileInfo::class);
    }

    public function current(): CountingSplFileInfo
    {
        ++self::$advanceCount;

        $info = parent::current();
        \assert($info instanceof CountingSplFileInfo, 'Iterator must yield CountingSplFileInfo instances; check setInfoClass()');

        return $info;
    }

    public static function reset(): void
    {
        self::$advanceCount = 0;
    }
}
