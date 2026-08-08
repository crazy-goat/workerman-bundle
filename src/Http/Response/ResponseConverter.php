<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response;

use CrazyGoat\WorkermanBundle\Exception\NoResponseStrategyException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

final readonly class ResponseConverter
{
    /**
     * Headers owned by the transport layer (Workerman's Http::encode() and
     * Response::__toString()). These are stripped from application input so the
     * transport is the sole authority on message framing — see issue #579.
     */
    private const TRANSPORT_HEADERS = [
        'content-length',
        'accept-ranges',
        'transfer-encoding',
    ];

    /** @var ResponseConverterStrategyInterface[] */
    private array $strategies;

    /**
     * @param iterable<ResponseConverterStrategyInterface> $strategies
     */
    public function __construct(iterable $strategies)
    {
        $this->strategies = iterator_to_array($strategies, false);
    }

    public function convert(SymfonyResponse $response, TcpConnection $connection, string $protocolVersion): WorkermanResponse
    {
        $headers = $this->extractHeaders($response);

        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($response)) {
                return $strategy->convert($response, $headers, $connection, $protocolVersion);
            }
        }

        throw new NoResponseStrategyException($response::class);
    }

    /**
     * Extracts and normalises Symfony headers for handoff to a Workerman Response.
     *
     * Two transformations are applied centrally so every strategy benefits and
     * the transport layer stays the sole authority on message framing:
     *
     * 1. Transport-owned headers are stripped. Workerman's Http::encode() and
     *    Response::__toString() compute Content-Length, Accept-Ranges and
     *    Transfer-Encoding themselves and merge (via array_merge_recursive)
     *    rather than overwrite, which would otherwise emit duplicates — and
     *    duplicates that disagree are a response-desync primitive (issue #579).
     *    Content-Range is NOT stripped: Workerman sets it via header() (which
     *    overwrites), and Symfony's value is correct for ranged responses.
     *
     * 2. Single-valued headers are flattened from list<string|null> to a string.
     *    Workerman's Response::withHeaders() merges recursively, so passing a
     *    list for a header that encode() also sets produces an array of both
     *    values. Set-Cookie is the one header that legitimately needs multiple
     *    values, so it keeps its array shape.
     *
     * @return array<string, string|list<string|null>>
     */
    private function extractHeaders(SymfonyResponse $response): array
    {
        $normalized = [];
        foreach ($response->headers->all() as $name => $values) {
            $normalizedName = $this->normalizeHeaderName($name);

            if (in_array(strtolower($normalizedName), self::TRANSPORT_HEADERS, true)) {
                continue;
            }

            $flattened = $this->flattenHeaderValues($normalizedName, $values);
            if ($flattened === [] || $flattened === '') {
                // Drop headers whose values are all null/empty so they are
                // not emitted as empty lines on the wire.
                continue;
            }

            $normalized[$normalizedName] = $flattened;
        }

        return $normalized;
    }

    /**
     * @param list<string|null> $values
     * @return string|list<string>
     */
    private function flattenHeaderValues(string $name, array $values): string|array
    {
        $nonNull = array_values(array_filter($values, static fn(?string $v): bool => $v !== null));

        $isSetCookie = strcasecmp($name, 'Set-Cookie') === 0;

        if (!$isSetCookie && count($nonNull) === 1) {
            return $nonNull[0];
        }

        return $nonNull;
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
