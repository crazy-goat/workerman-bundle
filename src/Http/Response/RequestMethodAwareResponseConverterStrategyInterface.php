<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response;

use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Opt-in extension of {@see ResponseConverterStrategyInterface} for strategies
 * that need the HTTP request method to produce a correct response.
 *
 * The canonical case is a HEAD request, which MUST NOT carry a message body
 * (RFC 9110 §9.3.2): a file strategy that would otherwise stream the file
 * bytes needs to know the method is HEAD so it can emit a bodyless response.
 *
 * {@see ResponseConverter} dispatches on `instanceof` and only passes the
 * request method to strategies implementing this interface. Strategies that
 * only implement the base interface keep working unchanged with the
 * 4-argument convert(), so adding this interface is backward-compatible for
 * external/custom strategies (issue #683).
 */
interface RequestMethodAwareResponseConverterStrategyInterface extends ResponseConverterStrategyInterface
{
    /**
     * Convert Symfony response to Workerman response, with the request method.
     *
     * Same contract as {@see ResponseConverterStrategyInterface::convert()},
     * plus the request method so the strategy can apply method-specific
     * framing rules (e.g. omitting the body for HEAD, RFC 9110 §9.3.2).
     *
     * @param array<string, string|list<string|null>> $headers Pre-extracted headers
     *        (see the base interface); for HEAD requests the application-
     *        provided Content-Length is preserved (issue #643)
     * @param string $protocolVersion The request's HTTP protocol version
     * @param string $requestMethod The HTTP request method (e.g. 'GET', 'HEAD')
     */
    public function convert(SymfonyResponse $response, array $headers, TcpConnection $connection, string $protocolVersion, string $requestMethod = 'GET'): WorkermanResponse;
}
