<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\RequestMethodAwareResponseConverterStrategyInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

final readonly class StreamedResponseStrategy implements RequestMethodAwareResponseConverterStrategyInterface
{
    private const MIN_CHUNK_SIZE = 8192;

    public function __construct(
        private int $chunkSize = self::MIN_CHUNK_SIZE,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(SymfonyResponse $response): bool
    {
        return $response instanceof StreamedResponse;
    }

    public function convert(SymfonyResponse $response, array $headers, TcpConnection $connection, string $protocolVersion, string $requestMethod = 'GET', bool $shouldClose = false): WorkermanResponse
    {
        if (strcasecmp($requestMethod, 'HEAD') === 0) {
            return $this->convertHead($response, $headers, $connection, $protocolVersion, $shouldClose);
        }

        $isHttp10 = $protocolVersion === '1.0';
        $sendChunkSize = max($this->chunkSize, self::MIN_CHUNK_SIZE);

        $dropped = false;
        $send = static function (string $data) use ($connection, &$dropped): void {
            if ($dropped) {
                return;
            }
            if ($connection->send($data, true) === false) {
                $dropped = true;
            }
        };

        $head = $this->buildHeaderString($headers, $response->getStatusCode(), $protocolVersion, $shouldClose);
        $send($head);

        if ($connection->context instanceof \stdClass) {
            $connection->context->responseSentDirectly = true;
        }

        $frame = static fn(string $chunk): string => $isHttp10
            ? $chunk
            : dechex(strlen($chunk)) . "\r\n{$chunk}\r\n";

        $initialLevel = ob_get_level();
        $obStarted = ob_start(static function (string $chunk) use ($send, $frame): string {
            if ($chunk !== '') {
                $send($frame($chunk));
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

        if (!$isHttp10) {
            $send("0\r\n\r\n");
        }

        if ($connection->context instanceof \stdClass) {
            $connection->context->responseSentDirectly = true;
        }

        if ($dropped) {
            $this->logger->warning('Streamed response was cut: the send buffer is full or the connection is closed. The connection is closed.');
            $connection->destroy();
        }

        return new WorkermanResponse($response->getStatusCode(), $headers, '');
    }

    /**
     * @param array<string, string|list<string|null>> $headers
     */
    private function convertHead(SymfonyResponse $response, array $headers, TcpConnection $connection, string $protocolVersion, bool $shouldClose = false): WorkermanResponse
    {
        $head = $this->buildHeaderString($headers, $response->getStatusCode(), $protocolVersion, $shouldClose);
        $connection->send($head, true);

        if ($connection->context instanceof \stdClass) {
            $connection->context->responseSentDirectly = true;
        }

        return new WorkermanResponse($response->getStatusCode(), $headers, '');
    }

    /**
     * @param array<string, string|list<string|null>> $headers
     */
    private function buildHeaderString(array $headers, int $statusCode, string $protocolVersion, bool $shouldClose = false): string
    {
        $reason = WorkermanResponse::PHRASES[$statusCode] ?? 'Unknown';
        $head = "HTTP/{$protocolVersion} {$statusCode} {$reason}\r\n";

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
            if (strcasecmp($name, 'Connection') === 0) {
                continue;
            }
            foreach ((array) $values as $value) {
                if ($value !== null && strpbrk($value, "\r\n") !== false) {
                    continue;
                }
                $head .= "{$name}: {$value}\r\n";
            }
        }

        if ($protocolVersion === '1.0' || $shouldClose) {
            $head .= "Connection: close\r\n";
        }

        if ($protocolVersion !== '1.0') {
            $head .= "Transfer-Encoding: chunked\r\n";
        }

        return $head . "\r\n";
    }
}
