<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategyInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

final readonly class DefaultResponseStrategy implements ResponseConverterStrategyInterface
{
    public function supports(SymfonyResponse $response): true
    {
        return true;
    }

    public function convert(SymfonyResponse $response, array $headers, TcpConnection $connection, string $protocolVersion): WorkermanResponse
    {
        // $protocolVersion is intentionally unused: this strategy returns a
        // regular WorkermanResponse whose status line is derived by Workerman
        // itself; HttpRequestHandler::sendResponse() stamps the request's
        // protocol version centrally before encoding.
        $body = strval($response->getContent());
        $contentLength = null;

        // ResponseConverter preserves the application-provided Content-Length
        // for HEAD requests (the length the corresponding GET would produce,
        // RFC 9110 §9.3.2 — issue #643). The transport computes 0 from the
        // empty HEAD body, so serialize this case ourselves as exactly one
        // Content-Length. Any other occurrence of the header (non-empty body,
        // non-digit value) is stripped so the transport stays the sole
        // framing authority (issue #579).
        if (isset($headers['Content-Length'])) {
            $appContentLength = $headers['Content-Length'];
            unset($headers['Content-Length']);
            if ($body === '' && is_string($appContentLength) && ctype_digit($appContentLength)) {
                $contentLength = (int) $appContentLength;
            }
        }

        if ($contentLength !== null) {
            return new HeadResponse(
                $response->getStatusCode(),
                $headers,
                $contentLength,
            );
        }

        return new WorkermanResponse(
            $response->getStatusCode(),
            $headers,
            $body,
        );
    }
}
