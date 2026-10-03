<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\RequestMethodAwareResponseConverterStrategyInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Strategy for converting Symfony StreamedResponse to Workerman Response.
 *
 * Streams content in chunks via ob_start callback, forwarding each flushed
 * chunk directly to $connection->send(). This avoids buffering the entire
 * response body in memory, which is critical for long-running event-loop workers.
 *
 * The chunks are written in one event-loop tick, so a slow client cannot drain
 * the send buffer in between. When Workerman refuses a chunk because the send
 * buffer is full (`maxSendBufferSize`, 1 MB by default), the stream is stopped
 * and the connection is destroyed. The client then sees a broken chunked body
 * instead of a body that looks complete (issue #900).
 */
final readonly class StreamedResponseStrategy implements RequestMethodAwareResponseConverterStrategyInterface
{
    private const MIN_CHUNK_SIZE = 8192;

    public function __construct(
        private int $chunkSize = 2048,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(SymfonyResponse $response): bool
    {
        return $response instanceof StreamedResponse;
    }

    public function convert(SymfonyResponse $response, array $headers, TcpConnection $connection, string $protocolVersion, string $requestMethod = 'GET', bool $shouldClose = false): WorkermanResponse
    {
        // A HEAD request must not carry a body (RFC 9110 §9.3.2). Symfony's
        // prepare() sets the streamed flag for HEAD so sendContent() would emit
        // nothing, but the regular path still sends the chunked terminator
        // ("0\r\n\r\n") — a 5-byte message body. Emit the same head the GET
        // would carry with no body and no terminator instead (issue #683). A
        // StreamedResponse has no known content length, so HeadResponse (which
        // rewrites Content-Length) does not apply; mirroring the GET framing
        // satisfies RFC 9110 §9.3.2 "same header fields".
        if (strcasecmp($requestMethod, 'HEAD') === 0) {
            return $this->convertHead($response, $headers, $connection, $protocolVersion, $shouldClose);
        }

        $isHttp10 = $protocolVersion === '1.0';
        $sendChunkSize = max($this->chunkSize, self::MIN_CHUNK_SIZE);

        $dropped = false;
        // Sends data unless an earlier send was refused. Workerman returns false
        // when it drops the data (full send buffer or closed connection).
        $send = static function (string $data) use ($connection, &$dropped): void {
            if ($dropped) {
                return;
            }
            if ($connection->send($data, true) === false) {
                $dropped = true;
            }
        };

        $head = $this->buildHeaderString($headers, $response->getStatusCode(), $protocolVersion, $shouldClose);
        $send($head);

        // From here the response is on the wire. If the callback throws,
        // HttpRequestHandler sees this flag and does not write a second
        // response into the open body (issue #901).
        if ($connection->context instanceof \stdClass) {
            $connection->context->responseSentDirectly = true;
        }

        // HTTP/1.0 has no chunked transfer encoding; the body is streamed raw
        // and the connection is closed by HttpRequestHandler (the head carries
        // Connection: close). For HTTP/1.1 each flushed chunk is hex-framed.
        $frame = static fn(string $chunk): string => $isHttp10
            ? $chunk
            : dechex(strlen($chunk)) . "\r\n{$chunk}\r\n";

        $initialLevel = ob_get_level();
        $obStarted = ob_start(static function (string $chunk) use ($send, $frame): string {
            if ($chunk !== '') {
                $send($frame($chunk));
            }

            return '';
        }, $sendChunkSize);

        if (!$obStarted) {
            throw new \RuntimeException('Failed to start output buffering');
        }

        try {
            $response->sendContent();
        } catch (\Throwable $e) {
            while (ob_get_level() > $initialLevel) {
                ob_end_clean();
            }
            throw $e;
        }

        while (ob_get_level() > $initialLevel) {
            ob_end_flush();
        }

        if (!$isHttp10) {
            $send("0\r\n\r\n");
        }

        if ($connection->context instanceof \stdClass) {
            $connection->context->responseSentDirectly = true;
        }

        if ($dropped) {
            $this->logger->warning('Streamed response was cut: the send buffer is full or the connection is closed. The connection is closed.');
            $connection->destroy();
        }

        return new WorkermanResponse($response->getStatusCode(), $headers, '');
    }

    /**
     * Send only the head for a HEAD request: the same status line and headers
     * the GET would carry (Transfer-Encoding: chunked for HTTP/1.1,
     * Connection: close for HTTP/1.0, and Connection: close for HTTP/1.1 when
     * the request asks for it — issue #621), with no body and no chunked
     * terminator.
     *
     * @param array<string, string|list<string|null>> $headers
     */
    private function convertHead(SymfonyResponse $response, array $headers, TcpConnection $connection, string $protocolVersion, bool $shouldClose = false): WorkermanResponse
    {
        $head = $this->buildHeaderString($headers, $response->getStatusCode(), $protocolVersion, $shouldClose);
        $connection->send($head, true);

        if ($connection->context instanceof \stdClass) {
            $connection->context->responseSentDirectly = true;
        }

        return new WorkermanResponse($response->getStatusCode(), $headers, '');
    }

    /**
     * @param array<string, string|list<string|null>> $headers
     */
    private function buildHeaderString(array $headers, int $statusCode, string $protocolVersion, bool $shouldClose = false): string
    {
        $reason = WorkermanResponse::PHRASES[$statusCode] ?? 'Unknown';
        $head = "HTTP/{$protocolVersion} {$statusCode} {$reason}\r\n";

        foreach ($headers as $name => $values) {
            if (strpbrk($name, ":\r\n") !== false) {
                continue;
            }
            // Belt-and-braces: ResponseConverter already strips these, but keep
            // the guards so a future caller cannot reintroduce them.
            if (strcasecmp($name, 'Content-Length') === 0) {
                continue;
            }
            if (strcasecmp($name, 'Transfer-Encoding') === 0) {
                continue;
            }
            // This strategy owns the Connection header: the body framing
            // (chunked for HTTP/1.1, close-delimited for HTTP/1.0) and the
            // connection close decision are both strategy-controlled here, so
            // never emit a conflicting app-provided value — for HTTP/1.0
            // (close-delimited) and for HTTP/1.1 close replies alike
            // (issue #621). An app-set `Connection: keep-alive` would otherwise
            // be emitted verbatim while the handler closes the socket.
            if (strcasecmp($name, 'Connection') === 0) {
                continue;
            }
            foreach ((array) $values as $value) {
                if ($value !== null && strpbrk($value, "\r\n") !== false) {
                    continue;
                }
                $head .= "{$name}: {$value}\r\n";
            }
        }

        if ($protocolVersion === '1.0' || $shouldClose) {
            // HTTP/1.0 has no chunked transfer encoding (close-delimited), and
            // an HTTP/1.1 close reply carries Connection: close alongside
            // Transfer-Encoding: chunked (issue #621). Both cases close the
            // socket via HttpRequestHandler::shouldCloseConnection().
            $head .= "Connection: close\r\n";
        }

        if ($protocolVersion !== '1.0') {
            $head .= "Transfer-Encoding: chunked\r\n";
        }

        return $head . "\r\n";
    }
}
