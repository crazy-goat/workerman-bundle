<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Exception thrown when kernel creation fails.
 */
final class KernelCreationException extends KernelException
{
    public function __construct()
    {
        parent::__construct('Error creating Kernel instance');
    }
}
