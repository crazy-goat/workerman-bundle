<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Handler;

/**
 * @internal
 */
trait ServiceErrorListenerTrait
{
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
