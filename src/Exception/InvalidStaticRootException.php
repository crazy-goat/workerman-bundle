<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Exception thrown when a configured static files root directory does not exist.
 *
 * Thrown by {@see \CrazyGoat\WorkermanBundle\Runner::checkStaticRoots()}
 * instead of a bare `\RuntimeException`, so callers catching
 * {@see WorkermanExceptionInterface} cover this path too.
 * Extends {@see KernelException} → {@see WorkermanException} →
 * `\RuntimeException`, so callers catching `\RuntimeException` continue
 * to work. The message is forwarded to `\RuntimeException::__construct()`.
 */
final class InvalidStaticRootException extends KernelException
{
}
