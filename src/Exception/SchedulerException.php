<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Base exception for scheduler-related errors.
 *
 * Extends \InvalidArgumentException to preserve semantic correctness
 * for callers who catch \InvalidArgumentException.
 */
abstract class SchedulerException extends \InvalidArgumentException implements WorkermanExceptionInterface
{
}
