<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response;

use CrazyGoat\WorkermanBundle\Exception\NoResponseStrategyException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

final readonly class ResponseConverter
{
    /** @var ResponseConverterStrategyInterface[] */
    private array $strategies;

    /**
     * @param iterable<ResponseConverterStrategyInterface> $strategies
     */
    public function __construct(iterable $strategies)
    {
        $this->strategies = iterator_to_array($strategies, false);
    }

    public function convert(SymfonyResponse $response, TcpConnection $connection): WorkermanResponse
    {
        $headers = $this->extractHeaders($response);

        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($response)) {
                return $strategy->convert($response, $headers, $connection);
            }
        }

        throw new NoResponseStrategyException($response::class);
    }

    /**
     * @return array<string, list<string|null>>
     */
    private function extractHeaders(SymfonyResponse $response): array
    {
        $normalized = [];
        foreach ($response->headers->all() as $name => $values) {
            $normalized[$this->normalizeHeaderName($name)] = $values;
        }

        return $normalized;
    }

    /**
     * Normalizes a header name to proper case (e.g., "content-type" → "Content-Type").
     *
     * NOTE: ucfirst-based normalization has known limitations with acronyms:
     * - "etag" → "Etag" (not "ETag"), "content-md5" → "Content-Md5" (not "Content-MD5")
     * Per RFC 9110, HTTP header names are case-insensitive, so this is technically valid.
     * This approach is still strictly better than the old hardcoded 6-header map.
     */
    private function normalizeHeaderName(string $name): string
    {
        return implode('-', array_map(ucfirst(...), explode('-', $name)));
    }
}
