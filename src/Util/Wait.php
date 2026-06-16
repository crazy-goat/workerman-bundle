<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Util;

/**
 * Polls a condition until it returns true or a timeout elapses, using
 * exponential backoff between checks.
 *
 * Shared waiting strategy for all bundle polling helpers (e.g. waiting
 * for a status file to appear, or for a child process to exit). The
 * backoff starts at {@see DEFAULT_INITIAL_DELAY_MS} milliseconds and
 * doubles after each unsuccessful check up to {@see DEFAULT_MAX_DELAY_MS}.
 *
 * The deadline is checked before each sleep, so no extra sleep is
 * performed once the timeout has elapsed. The total wall time may
 * still exceed `$timeoutSeconds` by up to `$maxDelayMs` plus the
 * runtime of the final condition evaluation, because an in-flight
 * sleep started just before the deadline runs to completion.
 *
 * @internal
 */
final class Wait
{
    /**
     * Initial delay between condition checks, in milliseconds.
     *
     * Kept small so short-lived conditions (file already exists,
     * process already gone) are observed without a perceptible wait.
     */
    public const DEFAULT_INITIAL_DELAY_MS = 10;

    /**
     * Upper bound for the per-iteration delay, in milliseconds.
     *
     * Caps the exponential backoff so longer waits remain responsive
     * (a process that exits after several seconds is observed within
     * at most this many milliseconds).
     */
    public const DEFAULT_MAX_DELAY_MS = 250;

    private function __construct()
    {
    }

    /**
     * @param callable(): bool $condition         predicate evaluated on each tick;
     *                                            return true to stop waiting
     * @param int              $timeoutSeconds    upper bound on total wall time, in seconds
     * @param int              $initialDelayMs    initial sleep between checks, in milliseconds
     * @param int              $maxDelayMs        cap for the exponential backoff, in milliseconds
     *
     * @return bool true if the condition became satisfied within the
     *              timeout, false if the timeout elapsed first
     */
    public static function until(
        callable $condition,
        int $timeoutSeconds,
        int $initialDelayMs = self::DEFAULT_INITIAL_DELAY_MS,
        int $maxDelayMs = self::DEFAULT_MAX_DELAY_MS,
    ): bool {
        if ($timeoutSeconds < 0) {
            throw new \InvalidArgumentException(
                sprintf('Timeout must be non-negative, got %d.', $timeoutSeconds),
            );
        }
        if ($initialDelayMs < 1) {
            throw new \InvalidArgumentException(
                sprintf('Initial delay must be at least 1ms, got %d.', $initialDelayMs),
            );
        }
        if ($maxDelayMs < $initialDelayMs) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Max delay (%dms) must be greater than or equal to initial delay (%dms).',
                    $maxDelayMs,
                    $initialDelayMs,
                ),
            );
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $sleepMs = $initialDelayMs;

        while (true) {
            if ($condition()) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep($sleepMs * 1000);
            $sleepMs = min($sleepMs * 2, $maxDelayMs);
        }
    }
}
