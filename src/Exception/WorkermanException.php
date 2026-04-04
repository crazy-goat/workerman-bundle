<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Abstract base exception for all WorkermanBundle exceptions.
 *
 * All concrete exceptions in the bundle should extend this class,
 * which implements the WorkermanExceptionInterface marker interface.
 * This maintains backward compatibility with code that catches \RuntimeException.
 */
abstract class WorkermanException extends \RuntimeException implements WorkermanExceptionInterface
{
}
