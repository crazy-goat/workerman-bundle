<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategyInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

final class DefaultResponseStrategy implements ResponseConverterStrategyInterface
{
    public function supports(SymfonyResponse $response): true
    {
        return true;
    }

    public function convert(SymfonyResponse $response, array $headers, TcpConnection $connection): WorkermanResponse
    {
        return new WorkermanResponse(
            $response->getStatusCode(),
            $headers,
            strval($response->getContent()),
        );
    }
}
