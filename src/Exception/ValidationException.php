<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Base exception for validation errors.
 *
 * Extends \InvalidArgumentException to preserve semantic correctness
 * for callers who catch \InvalidArgumentException.
 */
abstract class ValidationException extends \InvalidArgumentException implements WorkermanExceptionInterface
{
}
