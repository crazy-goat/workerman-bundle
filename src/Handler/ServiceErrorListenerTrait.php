<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Handler;

/**
 * Shared onException body for TaskErrorListener and ProcessErrorListener.
 *
 * Both listeners log a critical message with the same structure, differing
 * only in the placeholder label ("task" vs "process") and the event type.
 *
 * @internal
 */
trait ServiceErrorListenerTrait
{
    /**
     * @param string $format  Log message format with "{label}" and "{message}" placeholders
     * @param string $label   Context key for the name (e.g. "task" or "process")
     * @param string $name    Value for the named context key
     * @param string $errorMessage The error message text
     */
    private function logServiceError(
        string $format,
        string $label,
        string $name,
        string $errorMessage,
        \Throwable $exception,
    ): void {
        $this->logger->critical($format, [
            'exception' => $exception,
            $label => $name,
            'message' => $errorMessage,
        ]);
    }
}
