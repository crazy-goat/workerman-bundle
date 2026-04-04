<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategyInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Protocols\Http\Response as WorkermanResponse;

class StreamedResponseStrategy implements ResponseConverterStrategyInterface
{
    public function supports(SymfonyResponse $response): bool
    {
        return $response instanceof StreamedResponse
            && !$response instanceof BinaryFileResponse;
    }

    public function convert(SymfonyResponse $response, array $headers): WorkermanResponse
    {
        /** @var StreamedResponse $response */
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        return new WorkermanResponse(
            $response->getStatusCode(),
            $headers,
            $content
        );
    }
}
