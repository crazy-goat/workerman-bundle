<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http;

/**
 * Encapsulates the single rule that decides whether an HTTP connection must
 * be closed after the current response.
 *
 * The rule was previously duplicated verbatim in
 * {@see \CrazyGoat\WorkermanBundle\Middleware\SymfonyController::__invoke()}
 * (so directly-sent streamed responses can echo `Connection: close` in the
 * head they build themselves — issue #621) and in
 * {@see HttpRequestHandler::shouldCloseConnection()} (so the socket is
 * closed and centrally-sent responses are stamped). A drift between the two
 * silently reproduces issue #621, so both call sites now delegate here.
 */
final class ConnectionIntent
{
    /**
     * Decide whether the connection carrying this request should be closed
     * after the response is sent.
     *
     * The connection is closed when the request uses HTTP/1.0 (which has no
     * keep-alive default) or when the client sent `Connection: close`
     * (case-insensitive). The match is against the raw header value, matching
     * the original duplicated checks byte-for-byte.
     */
    public static function shouldClose(Request $request): bool
    {
        return $request->protocolVersion() === '1.0'
            || strcasecmp((string) $request->header('Connection', ''), 'close') === 0;
    }
}
