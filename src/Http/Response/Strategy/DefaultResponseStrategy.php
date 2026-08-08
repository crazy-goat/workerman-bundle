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
        return new WorkermanResponse(
            $response->getStatusCode(),
            $headers,
            strval($response->getContent()),
        );
    }
}
