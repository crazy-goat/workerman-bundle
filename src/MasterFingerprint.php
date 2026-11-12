<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use CrazyGoat\WorkermanBundle\Util\ProcFileReader;
use CrazyGoat\WorkermanBundle\Util\ProcStatParser;

final readonly class MasterFingerprint
{
    public function __construct(
        public int $pid,
        public int $startTime,
        public int $uid,
    ) {
    }

    /**
     * @throws \RuntimeException
     */
    public static function capture(): self
    {
        $pid = \getmypid();
        if (!\is_int($pid) || $pid <= 0) {
            throw new \RuntimeException('Unable to determine current PID for fingerprint capture');
        }

        $uid = \posix_getuid();
        $startTime = self::readStartTimeForPid($pid);

        return new self($pid, $startTime, $uid);
    }

    public static function readStartTimeForPid(int $pid): int
    {
        if (PHP_OS_FAMILY !== 'Linux' || $pid <= 0) {
            return 0;
        }

        $content = ProcFileReader::read("/proc/{$pid}/stat");
        if ($content === null) {
            return 0;
        }

        $afterParts = ProcStatParser::splitFields($content);
        if ($afterParts === null || \count($afterParts) < 20) {
            return 0;
        }

        $candidate = (int) $afterParts[19];

        return max($candidate, 0);
    }

    public static function readUidForPid(int $pid): ?int
    {
        if (PHP_OS_FAMILY !== 'Linux' || $pid <= 0) {
            return null;
        }

        $content = ProcFileReader::read("/proc/{$pid}/status");
        if ($content === null) {
            return null;
        }

        if (\preg_match('/^Uid:\s+(\d+)/m', $content, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    public function toString(): string
    {
        return \sprintf('%d|%d|%d', $this->pid, $this->startTime, $this->uid);
    }

    public static function fromString(string $value): ?self
    {
        $value = \trim($value);
        if ($value === '') {
            return null;
        }

        $parts = \explode('|', $value);
        if (\count($parts) !== 3) {
            return null;
        }

        if (!\ctype_digit($parts[0]) || !\ctype_digit($parts[1]) || !\ctype_digit($parts[2])) {
            return null;
        }

        $pid = (int) $parts[0];
        $startTime = (int) $parts[1];
        $uid = (int) $parts[2];

        if ($pid <= 0) {
            return null;
        }

        return new self($pid, $startTime, $uid);
    }

    /**
     * @throws \RuntimeException
     */
    public function writeTo(string $path): void
    {
        $directory = \dirname($path);
        if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Unable to create directory "%s" for fingerprint file', $directory));
        }

        $tempPath = $directory . '/.' . \basename($path) . '.' . \bin2hex(\random_bytes(8)) . '.tmp';
        $content = $this->toString();

        $handle = @\fopen($tempPath, 'xb');
        if ($handle === false) {
            throw new \RuntimeException(\sprintf('Unable to create fingerprint temp file "%s"', $tempPath));
        }

        try {
            if (!@\chmod($tempPath, 0600)) {
                throw new \RuntimeException(\sprintf('Unable to chmod fingerprint temp file "%s" to 0600', $tempPath));
            }

            $written = @\fwrite($handle, $content);
            if ($written === false || $written !== \strlen($content)) {
                throw new \RuntimeException(\sprintf('Unable to write fingerprint temp file "%s"', $tempPath));
            }

            if (!@\fclose($handle)) {
                throw new \RuntimeException(\sprintf('Unable to close fingerprint temp file "%s"', $tempPath));
            }
        } catch (\Throwable $e) {
            @\fclose($handle);
            @\unlink($tempPath);
            throw $e;
        }

        if (!@\rename($tempPath, $path)) {
            @\unlink($tempPath);
            throw new \RuntimeException(\sprintf('Unable to rename fingerprint temp file to "%s"', $path));
        }
    }

    public static function readFrom(string $path): ?self
    {
        if (!\is_file($path) || !\is_readable($path)) {
            return null;
        }

        $content = @\file_get_contents($path);
        if (!\is_string($content) || $content === '') {
            return null;
        }

        return self::fromString($content);
    }
}
