<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http;

use Workerman\Connection\TcpConnection;

/**
 * Emits HTTP 103 Early Hints on the current connection.
 *
 * Symfony's `AbstractController::sendEarlyHints()` ends in
 * `Response::sendHeaders(103)`, which only writes to the wire when the SAPI
 * provides `headers_send()` (FrankenPHP). On the CLI SAPI the call is a
 * no-op, so the bundle polyfills `headers_send()`
 * (see `src/polyfill_headers_send.php`) and routes it here.
 *
 * The hook is armed per request by `SymfonyController` with the current
 * connection and protocol version, and disarmed in a `finally` block, so no
 * state leaks into the next request on a long-lived worker.
 *
 * Interim responses are HTTP/1.1 only: a 103 is never written towards an
 * HTTP/1.0 client, and keep-alive is unaffected because the 103 carries no
 * body — the final response is encoded and sent unchanged afterwards.
 */
final class EarlyHints
{
    private static ?TcpConnection $connection = null;
    private static string $protocolVersion = '1.1';

    /**
     * Arm the hook for the request currently handled on $connection.
     */
    public static function arm(TcpConnection $connection, string $protocolVersion): void
    {
        self::$connection = $connection;
        self::$protocolVersion = $protocolVersion;
    }

    /**
     * Disarm the hook. Must run after every request, also on failure.
     */
    public static function disarm(): void
    {
        self::$connection = null;
        self::$protocolVersion = '1.1';
    }

    /**
     * Write the interim response to the armed connection.
     *
     * Called by the `headers_send()` polyfill — do not call directly. The
     * headers staged by Symfony's `Response::sendHeaders()` via `header()`
     * are read from PHP's header list, written into the 103, and then
     * removed: the list is process-global, so without the cleanup the Link
     * headers of one request would leak into the next request's 103 on the
     * same worker.
     */
    public static function send(int $statusCode): void
    {
        try {
            $connection = self::$connection;
            if ($connection === null || $statusCode !== 103 || self::$protocolVersion !== '1.1') {
                return;
            }

            $head = "HTTP/1.1 103 Early Hints\r\n";
            foreach (headers_list() as $headerLine) {
                $head .= $headerLine . "\r\n";
            }
            $connection->send($head . "\r\n", true);
        } catch (\Throwable) {
            // Best-effort: a failed interim response must never break the
            // final response. The final send surfaces a broken connection.
        } finally {
            // Always clean the staged headers, even when the 103 was
            // skipped: they were staged for this interim response only and
            // must not leak into a later request on this worker.
            header_remove();
        }
    }
}
