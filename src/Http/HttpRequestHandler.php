<?php

declare(strict_types=1);

namespace Luzrain\WorkermanBundle\Http;

use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Luzrain\WorkermanBundle\Protocol\Http\Request\SymfonyRequest;
use Luzrain\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use Luzrain\WorkermanBundle\Utils;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

final class HttpRequestHandler
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly RebootStrategyInterface $rebootStrategy,
        private readonly HttpMessageFactoryInterface $psrHttpFactory,
        private readonly HttpFoundationFactoryInterface $httpFoundationFactory,
        private readonly WorkermanHttpMessageFactory $workermanHttpFactory,
        private readonly int $chunkSize,
    ) {
    }

    public function __invoke(
        TcpConnection $connection,
        Request | SymfonyRequest $workermanRequest,
        bool $serveFiles = true,
    ): void {
        if (PHP_VERSION_ID >= 80200) {
            \memory_reset_peak_usage();
        }

        if ($workermanRequest instanceof Request) {
            $request = $this->workermanHttpFactory->createRequest($workermanRequest);
        } else {
            $request = $workermanRequest;
        }

        $shouldCloseConnection = $this->shouldCloseConnection($request);

        if ($serveFiles && \is_file($file = $this->getPublicPathFile($request))) {
            $this->createfileResponse($connection, $shouldCloseConnection, $file);
        } else {
            $this->createApplicationResponse($connection, $shouldCloseConnection, $request);
        }
    }

    private function createfileResponse(TcpConnection $connection, bool $shouldCloseConnection, string $file): void
    {
        $mimeTypedetector = new FinfoMimeTypeDetector();
        $response = $this->responseFactory->createResponse()
            ->withHeader('Content-Type', $mimeTypedetector->detectMimeTypeFromPath($file) ?? 'application/octet-stream')
            ->withBody($this->streamFactory->createStreamFromFile($file));

        foreach ($this->generateResponse($response) as $chunk) {
            $connection->send($chunk, true);
        }

        if ($shouldCloseConnection) {
            $connection->close();
        }
    }

    private function createApplicationResponse(
        TcpConnection $connection,
        bool $shouldCloseConnection,
        SymfonyRequest | ServerRequestInterface $request,
    ): void {
        $this->kernel->boot();


        $symfonyRequest =
            $request instanceof SymfonyRequest ? $request : $this->httpFoundationFactory->createRequest($request);

        $symfonyResponse = $this->kernel->handle($symfonyRequest);
        $sprResponse = $this->psrHttpFactory->createResponse($symfonyResponse);

        if ($shouldCloseConnection) {
            $sprResponse = $sprResponse->withAddedHeader('Connection', 'close');
        }

        foreach ($this->generateResponse($sprResponse) as $chunk) {
            $connection->send($chunk, true);
        }

        if ($shouldCloseConnection) {
            $connection->close();
        }

        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($symfonyRequest, $symfonyResponse);
        }

        if ($this->rebootStrategy->shouldReboot()) {
            Utils::reboot();
        }
    }

    private function getPublicPathFile(SymfonyRequest | ServerRequestInterface $request): string
    {
        if ($request instanceof SymfonyRequest) {
            $checkFile = "{$this->kernel->getProjectDir()}/public{$request->getPathInfo()}";
        } else {
            $checkFile = "{$this->kernel->getProjectDir()}/public{$request->getUri()->getPath()}";
        }

        return str_replace('..', '/', $checkFile);
    }

    private function generateResponse(ResponseInterface $response): \Generator
    {
        $msg = 'HTTP/' . $response->getProtocolVersion() . ' ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase() . "\r\n";

        if ($response->getHeaderLine('Transfer-Encoding') === '' && $response->getHeaderLine('Content-Length') === '') {
            $msg .= 'Content-Length: ' . $response->getBody()->getSize() . "\r\n";
        }
        if ($response->getHeaderLine('Content-Type') === '') {
            $msg .= "Content-Type: text/html\r\n";
        }
        if ($response->getHeaderLine('Connection') === '') {
            $msg .= "Connection: keep-alive\r\n";
        }
        if ($response->getHeaderLine('Server') === '') {
            $msg .= "Server: workerman\r\n";
        }
        foreach ($response->getHeaders() as $name => $values) {
            $msg .= "$name: " . implode(', ', $values) . "\r\n";
        }

        yield "$msg\r\n";

        $response->getBody()->rewind();
        while (!$response->getBody()->eof()) {
            yield $response->getBody()->read($this->chunkSize);
        }
        $response->getBody()->close();
    }

    public function shouldCloseConnection(SymfonyRequest | ServerRequestInterface $psrRequest): bool
    {
        if ($psrRequest instanceof SymfonyRequest) {
            return $psrRequest->getProtocolVersion() === '1.0' || $psrRequest->headers->get('Connection') === 'close';
        }

        return $psrRequest->getProtocolVersion() === '1.0' || $psrRequest->getHeaderLine('Connection') === 'close';
    }
}
