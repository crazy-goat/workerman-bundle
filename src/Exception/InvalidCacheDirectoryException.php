<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Exception thrown when a runtime directory (cache, log, PID) cannot be
 * created or is otherwise unusable.
 *
 * Thrown by {@see \CrazyGoat\WorkermanBundle\Runner::applyWorkermanConfig()}
 * when `mkdir()` fails for a runtime directory. Extends {@see KernelException}
 * → {@see WorkermanException} → `\RuntimeException`, so callers catching
 * `\RuntimeException` continue to work. The message is forwarded to
 * `\RuntimeException::__construct()`.
 */
final class InvalidCacheDirectoryException extends KernelException
{
}
