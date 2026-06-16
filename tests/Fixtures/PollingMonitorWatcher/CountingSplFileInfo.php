<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Fixtures\PollingMonitorWatcher;

/**
 * SplFileInfo subclass that counts stat-related calls.
 *
 * Used by tests to verify PollingMonitorWatcher doesn't make redundant
 * stat() syscalls per file. Each stat-touching method bumps a shared
 * static counter so a single test can assert the cumulative call count.
 *
 * Every method that triggers a stat/lstat syscall is overridden so a
 * regression under any name (getFileInfo, getSize, isFile, etc.) is caught.
 */
final class CountingSplFileInfo extends \SplFileInfo
{
    public static int $statCallCount = 0;

    public static function reset(): void
    {
        self::$statCallCount = 0;
    }

    public function getMTime(): int
    {
        ++self::$statCallCount;

        return parent::getMTime();
    }

    public function getATime(): int
    {
        ++self::$statCallCount;

        return parent::getATime();
    }

    public function getCTime(): int
    {
        ++self::$statCallCount;

        return parent::getCTime();
    }

    public function getSize(): int|false
    {
        ++self::$statCallCount;

        return parent::getSize();
    }

    public function getPerms(): int
    {
        ++self::$statCallCount;

        return parent::getPerms();
    }

    public function getInode(): int
    {
        ++self::$statCallCount;

        return parent::getInode();
    }

    public function getOwner(): int
    {
        ++self::$statCallCount;

        return parent::getOwner();
    }

    public function getGroup(): int
    {
        ++self::$statCallCount;

        return parent::getGroup();
    }

    public function getType(): string
    {
        ++self::$statCallCount;

        return parent::getType();
    }

    public function isWritable(): bool
    {
        ++self::$statCallCount;

        return parent::isWritable();
    }

    public function isReadable(): bool
    {
        ++self::$statCallCount;

        return parent::isReadable();
    }

    public function isExecutable(): bool
    {
        ++self::$statCallCount;

        return parent::isExecutable();
    }

    public function isFile(): bool
    {
        ++self::$statCallCount;

        return parent::isFile();
    }

    public function isDir(): bool
    {
        ++self::$statCallCount;

        return parent::isDir();
    }

    public function isLink(): bool
    {
        ++self::$statCallCount;

        return parent::isLink();
    }

    public function getLinkTarget(): string|false
    {
        ++self::$statCallCount;

        return parent::getLinkTarget();
    }

    public function getRealPath(): string|false
    {
        ++self::$statCallCount;

        return parent::getRealPath();
    }

    public function getFileInfo(?string $class_name = null): \SplFileInfo
    {
        ++self::$statCallCount;

        $class_name ??= CountingSplFileInfo::class;

        return new $class_name($this->getPathname());
    }

    public function getPathInfo(?string $class_name = null): \SplFileInfo
    {
        ++self::$statCallCount;

        $class_name ??= CountingSplFileInfo::class;

        return new $class_name(\dirname($this->getPathname()));
    }
}
