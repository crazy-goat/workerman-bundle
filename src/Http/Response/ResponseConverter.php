<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response;

use CrazyGoat\WorkermanBundle\Exception\NoResponseStrategyException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

final readonly class ResponseConverter
{
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

    public function convert(SymfonyResponse $response, TcpConnection $connection, string $protocolVersion, string $requestMethod, bool $shouldClose = false): WorkermanResponse
    {
        $headers = $this->extractHeaders($response, $requestMethod);

        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($response)) {
                if ($strategy instanceof RequestMethodAwareResponseConverterStrategyInterface) {
                    return $strategy->convert($response, $headers, $connection, $protocolVersion, $requestMethod, $shouldClose);
                }

                return $strategy->convert($response, $headers, $connection, $protocolVersion);
            }
        }

        throw new NoResponseStrategyException($response::class);
    }

    /**
     * @return array<string, string|list<string|null>>
     */
    private function extractHeaders(SymfonyResponse $response, string $requestMethod): array
    {
        $isHead = strcasecmp($requestMethod, 'HEAD') === 0;
        $normalized = [];
        foreach ($response->headers->all() as $name => $values) {
            $normalizedName = $this->normalizeHeaderName($name, $lowerName);

            if (in_array($lowerName, self::TRANSPORT_HEADERS, true) && (!$isHead || $lowerName !== 'content-length')) {
                continue;
            }

            $flattened = $this->flattenHeaderValues($normalizedName, $values);
            if ($flattened === [] || $flattened === '') {
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
        $isSetCookie = strcasecmp($name, 'Set-Cookie') === 0;
        if (!$isSetCookie && count($values) === 1 && $values[0] !== null) {
            return $values[0];
        }

        $nonNull = array_values(array_filter($values, static fn(?string $v): bool => $v !== null));

        if (!$isSetCookie && count($nonNull) === 1) {
            return $nonNull[0];
        }

        return $nonNull;
    }

    private function normalizeHeaderName(string $name, ?string &$lower = null): string
    {
        return HeaderNameNormalizer::normalize($name, $lower);
    }
}
