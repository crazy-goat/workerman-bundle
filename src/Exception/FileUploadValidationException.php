<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Exception thrown when file upload validation fails.
 *
 * Implements ClientInputExceptionInterface so HttpRequestHandler
 * classifies it as a 400 client error (malformed multipart input),
 * not a 500 server fault. See issue #577.
 */
final class FileUploadValidationException extends ValidationException implements ClientInputExceptionInterface
{
}
