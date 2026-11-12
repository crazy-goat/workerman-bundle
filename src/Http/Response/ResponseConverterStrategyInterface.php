<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response;

use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

interface ResponseConverterStrategyInterface
{
    public function supports(SymfonyResponse $response): bool;

    /**
     * @param array<string, string|list<string|null>> $headers
     */
    public function convert(SymfonyResponse $response, array $headers, TcpConnection $connection, string $protocolVersion): WorkermanResponse;
}
