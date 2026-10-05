<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Exception thrown when warming up the cache in the forked process fails.
 *
 * Thrown by {@see \CrazyGoat\WorkermanBundle\Runner::warmUpCache()}
 * on fork failure, waitpid failure, timeout, or unexpected child status.
 * Extends {@see KernelException} → {@see WorkermanException} →
 * `\RuntimeException`, so callers catching `\RuntimeException` continue
 * to work. The message is forwarded to `\RuntimeException::__construct()`.
 */
final class CacheWarmupException extends KernelException
{
}
