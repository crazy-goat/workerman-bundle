<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Base exception for middleware-related errors.
 *
 * Extends \InvalidArgumentException to preserve semantic correctness
 * for callers who catch \InvalidArgumentException.
 */
abstract class MiddlewareException extends \InvalidArgumentException implements WorkermanExceptionInterface
{
}
