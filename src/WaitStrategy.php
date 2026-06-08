<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

/**
 * Shared polling strategy using exponential backoff.
 *
 * Both {@see StatusFileReader::waitForFile()} and
 * {@see ProcessInspector::waitForProcessToStop()} poll for a condition
 * with a delay loop. This class provides a consistent exponential-backoff
 * strategy so that both code paths behave similarly.
 *
 * The delay starts at {@see DEFAULT_INITIAL_DELAY_MS} and doubles each
 * iteration until {@see DEFAULT_MAX_DELAY_MS} is reached, reducing CPU
 * churn during long waits while keeping the first poll responsive.
 */
final readonly class WaitStrategy
{
    /**
     * Default initial sleep between polls (milliseconds).
     */
    private const int DEFAULT_INITIAL_DELAY_MS = 10;

    /**
     * Maximum sleep between polls (milliseconds).
     */
    private const int DEFAULT_MAX_DELAY_MS = 250;

    public function __construct(
        private int $initialDelayMs = self::DEFAULT_INITIAL_DELAY_MS,
        private int $maxDelayMs = self::DEFAULT_MAX_DELAY_MS,
    ) {
    }

    /**
     * Poll until $condition returns true or $timeoutSeconds elapse.
     *
     * Uses exponential backoff: starts at {@see $initialDelayMs}, doubles
     * each iteration, capped at {@see $maxDelayMs}.
     *
     * @param callable(): bool $condition Condition to check
     * @param int $timeoutSeconds Maximum wall-clock time to wait (seconds)
     * @return bool True if $condition returned true within timeout
     */
    public function waitFor(callable $condition, int $timeoutSeconds): bool
    {
        $startTime = time();
        $delayMs = $this->initialDelayMs;

        while (true) {
            if ($condition()) {
                return true;
            }

            if ((time() - $startTime) >= $timeoutSeconds) {
                return false;
            }

            \usleep($delayMs * 1000);
            $delayMs = \min($delayMs * 2, $this->maxDelayMs);
        }
    }
}
