<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

/**
 * Fingerprint of the Workerman master process recorded at start time.
 *
 * The fingerprint is written to a sidecar file (`<pid_file>.fingerprint`)
 * before the Workerman runner is invoked. It records the PID, start time
 * (in clock ticks since boot), and UID of the process that is expected
 * to become the master. {@see ProcessInspector} uses this fingerprint to
 * verify that a candidate PID really is the Workerman master — not an
 * unrelated co-located process whose command line happens to contain
 * the substring "WorkerMan".
 *
 * The fingerprint is a defense-in-depth measure against the
 * `/proc/cmdline` substring-match vulnerability described in issue #327.
 * Without it, any process whose argv contains "WorkerMan" could be
 * misidentified and signaled (potentially SIGKILL), causing a
 * denial-of-service on adjacent services.
 *
 * Platform notes:
 * - On Linux, the start time is read from `/proc/$pid/stat` field 22
 *   (clock ticks since boot). This is a cross-process comparable value.
 * - On non-Linux POSIX platforms, `/proc` is unavailable, so the start
 *   time is recorded as `0` and the start-time check is disabled.
 *   Fingerprint verification on these platforms relies on PID + UID
 *   matching only.
 */
final readonly class MasterFingerprint
{
    public function __construct(
        public int $pid,
        public int $startTime,
        public int $uid,
    ) {
    }

    /**
     * Capture the fingerprint of the current process.
     *
     * On Linux, the start time is read from `/proc/self/stat` field 22
     * (clock ticks since boot). On non-Linux platforms, the start time
     * is recorded as `0` — the start-time check is disabled on those
     * platforms and verification relies on PID + UID matching only.
     *
     * @throws \RuntimeException if the current PID cannot be determined
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

    /**
     * Read the start time of the given PID from `/proc/$pid/stat` field 22.
     *
     * Returns `0` on non-Linux platforms (where `/proc` is unavailable)
     * or when the start time cannot be determined. Callers should treat
     * a `0` start time as "start-time check disabled" and rely on PID +
     * UID matching only.
     */
    public static function readStartTimeForPid(int $pid): int
    {
        if (PHP_OS_FAMILY !== 'Linux' || $pid <= 0) {
            return 0;
        }

        $statFile = "/proc/{$pid}/stat";
        if (!\is_readable($statFile)) {
            return 0;
        }

        $content = @\file_get_contents($statFile);
        if (!\is_string($content) || $content === '') {
            return 0;
        }

        // The command name (field 2) can contain spaces and parentheses,
        // so we look for the last ')' and parse after it.
        $closeParen = \strrpos($content, ')');
        if ($closeParen === false) {
            return 0;
        }

        $afterParen = \substr($content, $closeParen + 1);
        $afterParts = \preg_split('/\s+/', \trim($afterParen));
        if (!\is_array($afterParts) || \count($afterParts) < 20) {
            return 0;
        }

        // After ')', the fields are: state(3), ppid(4), pgrp(5), ...
        // starttime is field 22 overall, which is index 19 after ')'.
        $candidate = (int) $afterParts[19];

        return max($candidate, 0);
    }

    /**
     * Read the UID of the given PID from `/proc/$pid/status`.
     *
     * Returns `null` if the UID cannot be determined (non-Linux platform,
     * missing or unreadable status file, malformed content).
     */
    public static function readUidForPid(int $pid): ?int
    {
        if (PHP_OS_FAMILY !== 'Linux' || $pid <= 0) {
            return null;
        }

        $statusFile = "/proc/{$pid}/status";
        if (!\is_readable($statusFile)) {
            return null;
        }

        $content = @\file_get_contents($statusFile);
        if (!\is_string($content)) {
            return null;
        }

        if (\preg_match('/^Uid:\s+(\d+)/m', $content, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Serialize the fingerprint to a single-line string.
     *
     * Format: `<pid>|<start_time>|<uid>` — pipe-separated, no trailing
     * newline. The pipe character is not valid in PIDs, start times, or
     * UIDs, so no escaping is needed.
     */
    public function toString(): string
    {
        return \sprintf('%d|%d|%d', $this->pid, $this->startTime, $this->uid);
    }

    /**
     * Parse a fingerprint from a single-line string.
     *
     * Returns null if the string is malformed or contains non-numeric
     * fields. Callers should treat a null result as "no fingerprint
     * available" and fall back to the legacy cmdline-based check.
     */
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
     * Write the fingerprint to the given path.
     *
     * The file is created with mode 0600 (owner read/write only) to
     * prevent other users from reading or tampering with the fingerprint.
     * Existing files are overwritten atomically via temp-file + rename.
     *
     * Uses `fopen()` with the `'xb'` mode (exclusive create) to atomically
     * create the temp file, then `chmod()` to set permissions. The
     * `chmod()` call runs before any content is written, closing the
     * window where the temp file could be readable by other users under
     * a permissive umask.
     *
     * @throws \RuntimeException if the file cannot be written
     */
    public function writeTo(string $path): void
    {
        $directory = \dirname($path);
        if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Unable to create directory "%s" for fingerprint file', $directory));
        }

        $tempPath = $directory . '/.' . \basename($path) . '.' . \bin2hex(\random_bytes(8)) . '.tmp';
        $content = $this->toString();

        // Open with exclusive create mode to atomically create the temp file.
        $handle = @\fopen($tempPath, 'xb');
        if ($handle === false) {
            throw new \RuntimeException(\sprintf('Unable to create fingerprint temp file "%s"', $tempPath));
        }

        try {
            // Set permissions before writing content. This closes the
            // window where the temp file could be readable by other users
            // under a permissive umask (e.g., umask 0000).
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

    /**
     * Read a fingerprint from the given path.
     *
     * Returns null if the file does not exist, is not readable, or
     * contains malformed content. Callers should treat a null result
     * as "no fingerprint available" and fall back to the legacy
     * cmdline-based check.
     */
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
