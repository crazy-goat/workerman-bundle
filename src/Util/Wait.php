<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Util;

/**
 * @internal
 */
final class Wait
{
    public const DEFAULT_INITIAL_DELAY_MS = 10;

    public const DEFAULT_MAX_DELAY_MS = 250;

    private function __construct()
    {
    }

    /**
     * @param callable(): bool $condition
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
