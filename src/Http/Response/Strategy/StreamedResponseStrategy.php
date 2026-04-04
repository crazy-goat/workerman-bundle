<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategyInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Strategy for converting Symfony StreamedResponse to Workerman Response.
 *
 * Phase 1 implementation: Uses output buffering to capture streamed content.
 *
 * LIMITATION: Infinite SSE streams (EventStreamResponse with generators using yield)
 * will block until completion. For true async SSE, Phase 2 implementation is needed.
 */
final class StreamedResponseStrategy implements ResponseConverterStrategyInterface
{
    public function supports(SymfonyResponse $response): bool
    {
        return $response instanceof StreamedResponse;
    }

    public function convert(SymfonyResponse $response, array $headers): WorkermanResponse
    {
        /** @var StreamedResponse $response */
        $initialLevel = ob_get_level();
        $obStarted = ob_start();

        if (!$obStarted) {
            throw new \RuntimeException('Failed to start output buffering');
        }

        try {
            $response->sendContent();
            $content = ob_get_clean() ?: '';
        } catch (\Throwable $e) {
            // Clean up output buffer on error
            while (ob_get_level() > $initialLevel) {
                ob_end_clean();
            }
            throw $e;
        }

        return new WorkermanResponse(
            $response->getStatusCode(),
            $headers,
            $content,
        );
    }
}
