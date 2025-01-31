<?php

declare(strict_types=1);

namespace Luzrain\WorkermanBundle\Http;

use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Luzrain\WorkermanBundle\DTO\RequestConverter;
use Luzrain\WorkermanBundle\Protocol\Http\Response\StreamedBinaryFileResponse;
use Luzrain\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use Luzrain\WorkermanBundle\Utils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Workerman\Connection\TcpConnection;

final class HttpRequestHandler implements StaticFileHandlerInterface
{
    private const CHUNK_SIZE = 4096;
    private ?string $rootDirectory = null;

    public function __construct(
        private readonly KernelInterface         $kernel,
        private readonly RebootStrategyInterface $rebootStrategy,
    )
    {
    }

    public function withRootDirectory(?string $rootDirectory): self
    {
        $this->rootDirectory = $rootDirectory !== null ? rtrim($rootDirectory, '/') : null;
        return $this;
    }

    public function __invoke(
        TcpConnection                     $connection,
        \Workerman\Protocols\Http\Request $request,
    ): void
    {
        if (PHP_VERSION_ID >= 80200) {
            \memory_reset_peak_usage();
        }
        $shouldCloseConnection = $this->shouldCloseConnection($request);

        if ($this->rootDirectory !== null && \is_file($file = $this->getPublicPathFile($request))) {
            $response = $this->handleFileRequest($file, RequestConverter::toSymfonyRequest($request));
            $this->sendAndClose($connection, $response, $shouldCloseConnection);

            return;
        }

        $symfonyRequest = RequestConverter::toSymfonyRequest($request);
        $response = $this->handleApplicationRequest($symfonyRequest);
        $this->sendAndClose($connection, $response, $shouldCloseConnection);

        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($symfonyRequest, $response);
        }

        if ($this->rebootStrategy->shouldReboot()) {
            Utils::reboot();
        }
    }

    private function sendAndClose(TcpConnection $connection, Response $response, bool $shouldCloseConnection): void
    {
        foreach ($this->generateResponse($response, $shouldCloseConnection) as $chunk) {
            $connection->send($chunk, true);
        }

        if ($shouldCloseConnection) {
            $connection->close();
        }
    }

    private function handleFileRequest(string $file, Request $request): Response
    {
        $response = new StreamedBinaryFileResponse($file);
        $response->headers->set(
            'Content-Type',
            (new FinfoMimeTypeDetector())->detectMimeTypeFromPath($file) ?? 'application/octet-stream',
        );

        $response->setChunkSize(self::CHUNK_SIZE);
        $response->prepare($request);

        return $response;
    }

    private function handleApplicationRequest(Request $request): Response
    {
        $this->kernel->boot();

        $response = $this->kernel->handle($request);
        $response->prepare($request);

        return $response;
    }

    private function getPublicPathFile(\Workerman\Protocols\Http\Request $request): string
    {
        return str_replace(
            '..',
            '/',
            "{$this->rootDirectory}{$request->path()}",
        );
    }

    private function prepareHeaders(Response $response, bool $shouldCloseConnection): \Generator
    {
        $headers = $response->headers->all();

        if (($headers['connection'][0] ?? '') === '') {
            yield "connection: " . ($shouldCloseConnection ? 'close' : 'keep-alive') . "\r\n";
        }

        if (($headers['transfer-encoding'][0] ?? '') === '') {
            yield "transfer-encoding: chunked\r\n";
        }

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                yield "$name: $value\r\n";
            }
        }
        yield "\r\n";
    }

    private function generateResponse(Response $response, bool $shouldCloseConnection): \Generator
    {
        yield \sprintf(
                'HTTP/%s %s %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                Response::$statusTexts[$response->getStatusCode()],
            ) . "\r\n";

        yield from $this->prepareHeaders($response, $shouldCloseConnection);

        yield from $response instanceof StreamedBinaryFileResponse ?
            $this->streamContent($response) : $this->emulateStreamContent($response);

        yield "0\r\n\r\n";
    }

    private function streamContent(StreamedBinaryFileResponse $response): \Generator
    {
        foreach ($response->streamContent() as $chunk) {
            yield dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
        }
    }

    private function emulateStreamContent(Response $response): \Generator
    {
        $content = $response->getContent();
        if ($content === false) {
            \ob_start();
            $response->sendContent();
            $content = \ob_get_clean();
        }

        if ($content === false || $content === '') {
            return;
        }

        foreach (str_split($content, self::CHUNK_SIZE) as $chunk) {
            yield dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
        }
    }

    public function shouldCloseConnection(\Workerman\Protocols\Http\Request $request): bool
    {
        return $request->protocolVersion() === '1.0' || $request->header('Connection', '') === 'close';
    }
}
