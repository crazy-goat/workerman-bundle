<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategyInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Protocols\Http\Response as WorkermanResponse;

class DefaultResponseStrategy implements ResponseConverterStrategyInterface
{
    public function supports(SymfonyResponse $response): bool
    {
        return true;
    }

    public function convert(SymfonyResponse $response, array $headers): WorkermanResponse
    {
        return new WorkermanResponse(
            $response->getStatusCode(),
            $headers,
            strval($response->getContent()),
        );
    }
}
