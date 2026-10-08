<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Exception thrown when the cache warmup timeout is not a positive integer.
 *
 * Thrown by {@see \CrazyGoat\WorkermanBundle\CacheWarmupTimeoutConfig::set()}
 * and {@see \CrazyGoat\WorkermanBundle\CacheWarmupTimeoutConfig::parseEnvRaw()}
 * (which feeds {@see \CrazyGoat\WorkermanBundle\CacheWarmupTimeoutConfig::resolve()}),
 * and by {@see \CrazyGoat\WorkermanBundle\Runner::__construct()}
 * when `cacheWarmupTimeout` is less than 1. Extends {@see ValidationException}
 * → `\InvalidArgumentException`, so callers catching `\InvalidArgumentException`
 * continue to work. The message is forwarded to
 * `\InvalidArgumentException::__construct()`.
 */
final class InvalidCacheWarmupTimeoutException extends ValidationException
{
}
