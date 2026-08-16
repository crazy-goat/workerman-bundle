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

    public function convert(SymfonyResponse $response, TcpConnection $connection, string $protocolVersion, string $requestMethod): WorkermanResponse
    {
        $headers = $this->extractHeaders($response, $requestMethod);

        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($response)) {
                // Method-aware strategies (file/streamed) need the request
                // method to apply method-specific framing rules, e.g. omitting
                // the body for HEAD (RFC 9110 §9.3.2, issue #683). Strategies
                // that only implement the base interface keep the 4-argument
                // convert() — the instanceof dispatch keeps
                // ResponseConverterStrategyInterface backward-compatible for
                // external/custom strategies.
                if ($strategy instanceof RequestMethodAwareResponseConverterStrategyInterface) {
                    return $strategy->convert($response, $headers, $connection, $protocolVersion, $requestMethod);
                }

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
     *    One exception: for HEAD requests the application-provided
     *    Content-Length is preserved. A HEAD response legitimately carries the
     *    length the corresponding GET would produce over an empty body (RFC
     *    9110 §9.3.2, Symfony's Response::prepare() keeps the header for
     *    HEAD), and the transport would compute 0 from the empty body (issue
     *    #643). DefaultResponseStrategy serializes this case as a single,
     *    correct Content-Length; the other strategies drop the header again
     *    defensively.
     *
     * 2. Single-valued headers are flattened from list<string|null> to a string.
     *    Workerman's Response::withHeaders() merges recursively, so passing a
     *    list for a header that encode() also sets produces an array of both
     *    values. Set-Cookie is the one header that legitimately needs multiple
     *    values, so it keeps its array shape.
     *
     * @return array<string, string|list<string|null>>
     */
    private function extractHeaders(SymfonyResponse $response, string $requestMethod): array
    {
        $isHead = strcasecmp($requestMethod, 'HEAD') === 0;
        $normalized = [];
        foreach ($response->headers->all() as $name => $values) {
            $normalizedName = $this->normalizeHeaderName($name);
            $lowerName = strtolower($normalizedName);

            if (in_array($lowerName, self::TRANSPORT_HEADERS, true) && (!$isHead || $lowerName !== 'content-length')) {
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
     * Header names are normalised to proper case via HeaderNameNormalizer
     * (e.g., "content-type" → "Content-Type", "etag" → "ETag"), whose cache
     * is bounded per issue #574. Per RFC 9110 header names are
     * case-insensitive, so uncorrected forms would still be valid; the
     * normalisation just matches common usage.
     */
    private function normalizeHeaderName(string $name): string
    {
        return HeaderNameNormalizer::normalize($name);
    }
}
