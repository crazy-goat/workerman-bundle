<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response;

use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Protocols\Http\Response as WorkermanResponse;

class ResponseConverter
{
    /** @var ResponseConverterStrategyInterface[] */
    private readonly array $strategies;

    public function __construct(iterable $strategies)
    {
        $this->strategies = iterator_to_array($strategies, false);
    }

    public function convert(SymfonyResponse $response): WorkermanResponse
    {
        $headers = $this->extractHeaders($response);

        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($response)) {
                return $strategy->convert($response, $headers);
            }
        }

        throw new \LogicException(sprintf(
            'No strategy found for response type: %s',
            get_class($response)
        ));
    }

    /**
     * @return array<string, list<string|null>>
     */
    private function extractHeaders(SymfonyResponse $response): array
    {
        $headers = $response->headers->all();

        // Fix header names (lowercase to proper case)
        $fixHeaders = [
            'content-type' => 'Content-Type',
            'connection' => 'Connection',
            'transfer-encoding' => 'Transfer-Encoding',
            'server' => 'Server',
            'content-disposition' => 'Content-Disposition',
            'last-modified' => 'Last-Modified',
        ];

        foreach ($fixHeaders as $lower => $proper) {
            if (isset($headers[$lower])) {
                $headers[$proper] = $headers[$lower];
                unset($headers[$lower]);
            }
        }

        return $headers;
    }
}
