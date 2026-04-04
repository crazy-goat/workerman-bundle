<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Exception thrown when kernel creation fails.
 */
final class KernelCreationException extends KernelException
{
    public function __construct(string $message = 'Error creating Kernel instance', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
