<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Exception thrown when an unsupported listen scheme is provided.
 *
 * Supported schemes: http://, https://. WebSocket schemes (ws://, wss://) are
 * rejected with a dedicated message until WebSocket support is implemented.
 */
final class UnsupportedListenSchemeException extends WorkermanException
{
}
