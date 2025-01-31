<?php

declare(strict_types=1);

namespace Luzrain\WorkermanBundle\Http;

use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Luzrain\WorkermanBundle\Protocol\Http\Request\SymfonyRequest;
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
    ) {
    }

    public function withRootDirectory(?string $rootDirectory): self
    {
        $this->rootDirectory = $rootDirectory !== null ? rtrim($rootDirectory, '/') : null;
        return $this;
    }

    public function __invoke(
        TcpConnection                     $connection,
        \Workerman\Protocols\Http\Request $request,
    ): void {
        if (PHP_VERSION_ID >= 80200) {
            \memory_reset_peak_usage();
        }

        $shouldCloseConnection = $this->shouldCloseConnection($request);

        $symfonyRequest = new SymfonyRequest($request);
        if ($this->rootDirectory !== null && \is_file($file = $this->getPublicPathFile($request))) {
            $response = $this->handleFileRequest($file, $symfonyRequest);
            $this->sendAndClose($connection, $response, $shouldCloseConnection);

            return;
        }

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

    private function prepareHeaders(Response $response, bool $shouldCloseConnection): string
    {
        $headers = $response->headers->all();

        if (($headers['connection'][0] ?? '') === '') {
            $headers['Connection'][0] = $shouldCloseConnection ? 'close' : 'keep-alive';
        }

        if (($headers['transfer-encoding'][0] ?? '') === '') {
            $headers['transfer-encoding'][0] = 'chunked';
        }

        $lines = [];
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $lines[] = "$name: $value";
            }
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    private function generateResponse(Response $response, bool $shouldCloseConnection): \Generator
    {
        $headers = $this->prepareHeaders($response, $shouldCloseConnection);
        yield \sprintf(
            'HTTP/%s %s %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            Response::$statusTexts[$response->getStatusCode()],
        ) . "\r\n" . $headers . "\r\n";

        $content = $response->getContent();

        if ($content === false) {
            \ob_start();
            $response->sendContent();
            $content = \ob_get_clean();
        }

        if ($content === false || $content === '') {
            yield "0\r\n\r\n";

            return;
        }

        foreach (str_split($content, self::CHUNK_SIZE) as $chunk) {
            yield dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
        }

        yield "0\r\n\r\n";
    }

    public function shouldCloseConnection(\Workerman\Protocols\Http\Request $request): bool
    {
        return $request->protocolVersion() === '1.0' || $request->header('Connection', '') === 'close';
    }
}
