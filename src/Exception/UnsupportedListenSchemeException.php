<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Exception thrown when an unsupported listen scheme is provided.
 *
 * Supported schemes: http://, https://, ws://, wss://
 */
final class UnsupportedListenSchemeException extends WorkermanException
{
}
