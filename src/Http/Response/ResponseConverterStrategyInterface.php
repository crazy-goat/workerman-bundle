<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response;

use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
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
     * @param array<string, list<string|null>> $headers Pre-extracted headers
     */
    public function convert(SymfonyResponse $response, array $headers): WorkermanResponse;
}
