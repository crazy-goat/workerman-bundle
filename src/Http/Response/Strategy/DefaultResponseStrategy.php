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
        $body = strval($response->getContent());
        $contentLength = null;

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
