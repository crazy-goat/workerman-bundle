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
    private ?string $rootDirectory = null;

    public function __construct(
        private readonly KernelInterface         $kernel,
        private readonly RebootStrategyInterface $rebootStrategy,
        private readonly int                     $chunkSize,
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

        if ($this->rootDirectory !== null && \is_file($file = $this->getPublicPathFile($request))) {
            $this->createFileResponse($connection, $file, new SymfonyRequest($request), $shouldCloseConnection);
        } else {
            $this->createApplicationResponse($connection, new SymfonyRequest($request), $shouldCloseConnection);
        }
    }

    private function createFileResponse(TcpConnection $connection, string $file, Request $request, bool $shouldCloseConnection): void
    {
        $response = new StreamedBinaryFileResponse($file);
        $response->headers->set(
            'Content-Type',
            (new FinfoMimeTypeDetector())->detectMimeTypeFromPath($file) ?? 'application/octet-stream',
        );

        $response->setChunkSize($this->chunkSize);
        $response->prepare($request);
        $this->prepareHeaders($response, $shouldCloseConnection);
        $connection->send($response->__toString(), true);

        foreach ($response->streamContent() as $chunk) {
            $connection->send($chunk, true);
        }

        if ($shouldCloseConnection) {
            $connection->close();
        }
    }

    private function createApplicationResponse(
        TcpConnection $connection,
        Request       $request,
        bool          $shouldCloseConnection,
    ): void {
        $this->kernel->boot();

        $response = $this->kernel->handle($request);

        foreach ($this->generateResponse($request, $response, $shouldCloseConnection) as $chunk) {
            $connection->send($chunk, true);
        }

        if ($shouldCloseConnection) {
            $connection->close();
        }

        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($request, $response);
        }

        if ($this->rebootStrategy->shouldReboot()) {
            Utils::reboot();
        }
    }

    private function getPublicPathFile(\Workerman\Protocols\Http\Request $request): string
    {
        return str_replace(
            '..',
            '/',
            "{$this->rootDirectory}{$request->path()}",
        );
    }

    private function prepareHeaders(Response $response, bool $shouldCloseConnection): void
    {
        if ($response->headers->get('Connection', '') === '') {
            if ($shouldCloseConnection) {
                $response->headers->set('Connection', 'close');
            } else {
                $response->headers->set('Connection', 'keep-alive');
            }
        }

        if ($response->headers->get('Transfer-Encoding', '') === '' &&
            $response->headers->get('Content-Length', '') === '') {
            $length = strlen((string) $response->getContent());
            $response->headers->set('Content-Length', strval($length));
        }

        if ($response->headers->get('Server', '') === '') {
            $response->headers->set('Server', 'workerman');
        }
    }

    private function generateResponse(Request $request, Response $response, bool $shouldCloseConnection): \Generator
    {
        $response->prepare($request);
        $this->prepareHeaders($response, $shouldCloseConnection);


        yield \sprintf(
            'HTTP/%s %s %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            Response::$statusTexts[$response->getStatusCode()],
        ) . "\r\n" . $response->headers->__toString() . "\r\n";

        $content = $response->getContent();

        if ($content === false) {
            ob_start();
            $response->sendContent();
            $content = ob_get_clean();
        }

        if ($content === false || $content === '') {
            return;
        }

        foreach (str_split($content, max(1, $this->chunkSize)) as $chunk) {
            yield $chunk;
        }
    }

    public function shouldCloseConnection(\Workerman\Protocols\Http\Request $request): bool
    {
        return $request->protocolVersion() === '1.0' || $request->header('Connection', '') !== 'keep-alive';
    }
}
