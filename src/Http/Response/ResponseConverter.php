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
     * Results are cached in a static lookup table so each unique header name is
     * normalised at most once per worker lifetime -- the hot path then becomes O(1).
     *
     * A corrections table handles irregular acronyms that ucfirst cannot produce:
     *   "etag"             → "ETag"
     *   "content-md5"      → "Content-MD5"
     *   "www-authenticate" → "WWW-Authenticate"
     *   "dnt"              → "DNT"
     *
     * Per RFC 9110, HTTP header names are case-insensitive, so the uncorrected
     * forms would still be valid; the corrections just match common usage.
     */
    private function normalizeHeaderName(string $name): string
    {
        static $cache = [];
        static $corrections = [
            'etag' => 'ETag',
            'content-md5' => 'Content-MD5',
            'www-authenticate' => 'WWW-Authenticate',
            'dnt' => 'DNT',
        ];

        $lower = strtolower($name);

        return $cache[$lower] ?? $cache[$lower] = $corrections[$lower]
            ?? implode('-', array_map(ucfirst(...), explode('-', $name)));
    }
}
