<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategyInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

final readonly class DefaultResponseStrategy implements ResponseConverterStrategyInterface
{
    private const MIN_CHUNK_SIZE = 8192;

    public function __construct(
        private int $chunkSize = 2048,
    ) {
    }

    public function supports(SymfonyResponse $response): true
    {
        return true;
    }

    public function convert(SymfonyResponse $response, array $headers, TcpConnection $connection): WorkermanResponse
    {
        $content = strval($response->getContent());
        $contentLength = strlen($content);

        if ($contentLength <= $this->chunkSize) {
            return new WorkermanResponse(
                $response->getStatusCode(),
                $headers,
                $content,
            );
        }

        $this->sendChunked($content, $contentLength, $headers, $connection, $response->getStatusCode());

        return new WorkermanResponse($response->getStatusCode(), $headers, '');
    }

    /**
     * @param array<string, list<string|null>> $headers
     */
    private function sendChunked(string $content, int $contentLength, array $headers, TcpConnection $connection, int $statusCode): void
    {
        $sendChunkSize = max($this->chunkSize, self::MIN_CHUNK_SIZE);

        $head = $this->buildHeaderString($headers, $contentLength, $statusCode);
        $connection->send($head, true);

        $offset = 0;
        while ($offset < $contentLength) {
            $connection->send(substr($content, $offset, $sendChunkSize), true);
            $offset += $sendChunkSize;
        }

        if ($connection->context instanceof \stdClass) {
            $connection->context->responseSentDirectly = true;
        }
    }

    /**
     * @param array<string, list<string|null>> $headers
     */
    private function buildHeaderString(array $headers, int $contentLength, int $statusCode): string
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
            foreach ($values as $value) {
                if ($value !== null && strpbrk($value, "\r\n") !== false) {
                    continue;
                }
                $head .= "{$name}: {$value}\r\n";
            }
        }

        $head .= "Content-Length: {$contentLength}\r\n";

        return $head . "\r\n";
    }
}
