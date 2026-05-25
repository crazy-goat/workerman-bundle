<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategyInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Strategy for converting Symfony StreamedResponse to Workerman Response.
 *
 * Streams content in chunks via ob_start callback, forwarding each flushed
 * chunk directly to $connection->send(). This avoids buffering the entire
 * response body in memory, which is critical for long-running event-loop workers.
 */
final readonly class StreamedResponseStrategy implements ResponseConverterStrategyInterface
{
    private const MIN_CHUNK_SIZE = 8192;

    public function __construct(
        private int $chunkSize = 2048,
    ) {
    }

    public function supports(SymfonyResponse $response): bool
    {
        return $response instanceof StreamedResponse;
    }

    public function convert(SymfonyResponse $response, array $headers, TcpConnection $connection): WorkermanResponse
    {
        $sendChunkSize = max($this->chunkSize, self::MIN_CHUNK_SIZE);

        $head = $this->buildHeaderString($headers, $response->getStatusCode());
        $connection->send($head, true);

        $initialLevel = ob_get_level();
        $obStarted = ob_start(function (string $chunk) use ($connection): string {
            if ($chunk !== '') {
                $connection->send(dechex(strlen($chunk)) . "\r\n{$chunk}\r\n", true);
            }

            return '';
        }, $sendChunkSize);

        if (!$obStarted) {
            throw new \RuntimeException('Failed to start output buffering');
        }

        try {
            $response->sendContent();
        } catch (\Throwable $e) {
            while (ob_get_level() > $initialLevel) {
                ob_end_clean();
            }
            throw $e;
        }

        while (ob_get_level() > $initialLevel) {
            ob_end_flush();
        }

        $connection->send("0\r\n\r\n", true);

        if ($connection->context instanceof \stdClass) {
            $connection->context->responseSentDirectly = true;
        }

        return new WorkermanResponse($response->getStatusCode(), $headers, '');
    }

    /**
     * @param array<string, list<string|null>> $headers
     */
    private function buildHeaderString(array $headers, int $statusCode): string
    {
        $reason = WorkermanResponse::PHRASES[$statusCode] ?? 'Unknown';
        $head = "HTTP/1.1 {$statusCode} {$reason}\r\n";

        foreach ($headers as $name => $values) {
            if (strpbrk($name, ":\r\n") !== false) {
                continue;
            }
            if (strcasecmp($name, 'Content-Length') === 0) {
                continue;
            }
            if (strcasecmp($name, 'Transfer-Encoding') === 0) {
                continue;
            }
            foreach ($values as $value) {
                if ($value !== null && strpbrk($value, "\r\n") !== false) {
                    continue;
                }
                $head .= "{$name}: {$value}\r\n";
            }
        }

        $head .= "Transfer-Encoding: chunked\r\n";

        return $head . "\r\n";
    }
}
