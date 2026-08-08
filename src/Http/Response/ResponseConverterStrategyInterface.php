<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response;

use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

interface ResponseConverterStrategyInterface
{
    /**
     * Check if this strategy can handle the given response.
     */
    public function supports(SymfonyResponse $response): bool;

    /**
     * Convert Symfony response to Workerman response.
     *
     * @param array<string, string|list<string|null>> $headers Pre-extracted headers,
     *        with transport-owned headers (Content-Length, Accept-Ranges,
     *        Transfer-Encoding) already stripped and single-valued headers
     *        flattened to strings (except Set-Cookie)
     * @param string $protocolVersion The request's HTTP protocol version
     *        (e.g. '1.1' or '1.0'); strategies that build their own status
     *        line must derive it from this value
     */
    public function convert(SymfonyResponse $response, array $headers, TcpConnection $connection, string $protocolVersion): WorkermanResponse;
}
