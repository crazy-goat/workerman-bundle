<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Thrown when a client request is structurally malformed and cannot be
 * converted to a Symfony Request.
 *
 * Extends \InvalidArgumentException to preserve semantic correctness for
 * callers who catch \InvalidArgumentException, and implements
 * ClientInputExceptionInterface so HttpRequestHandler classifies it as a
 * 400 client error (not a 500 server fault).
 *
 * See issue #577: control bytes in header values, invalid URI/method.
 */
final class MalformedRequestException extends \InvalidArgumentException implements ClientInputExceptionInterface
{
}
