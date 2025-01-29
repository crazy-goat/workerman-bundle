<?php

declare(strict_types=1);

namespace Luzrain\WorkermanBundle\Http;

use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Luzrain\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use Luzrain\WorkermanBundle\Utils;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Workerman\Connection\TcpConnection;

final class HttpRequestHandler
{
    public function __construct(
        private readonly KernelInterface         $kernel,
        private readonly RebootStrategyInterface $rebootStrategy,
        private readonly int                     $chunkSize,
    ) {
    }

    public function __invoke(
        TcpConnection $connection,
        Request       $request,
        bool          $serveFiles = true,
    ): void {
        if (PHP_VERSION_ID >= 80200) {
            \memory_reset_peak_usage();
        }

        $shouldCloseConnection = $this->shouldCloseConnection($request);

        if ($serveFiles && \is_file($file = $this->getPublicPathFile($request))) {
            $this->createFileResponse($connection, $file, $request, $shouldCloseConnection);
        } else {
            $this->createApplicationResponse($connection, $request, $shouldCloseConnection);
        }
    }

    private function createFileResponse(TcpConnection $connection, string $file, Request $request, bool $shouldCloseConnection): void
    {
        $response = new BinaryFileResponse($file);
        $response->headers->set(
            'Content-Type',
            (new FinfoMimeTypeDetector())->detectMimeTypeFromPath($file) ?? 'application/octet-stream',
        );

        foreach ($this->generateResponse($request, $response, $shouldCloseConnection) as $chunk) {
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

    private function getPublicPathFile(Request $request): string
    {
        return str_replace(
            '..',
            '/',
            "{$this->kernel->getProjectDir()}/public{$request->getPathInfo()}",
        );
    }

    private function generateResponse(Request $request, Response $response, bool $shouldCloseConnection): \Generator
    {
        $response->prepare($request);

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

        foreach (str_split($response->__toString(), $this->chunkSize) as $chunk) {
            yield $chunk;
        }
    }

    public function shouldCloseConnection(Request $request): bool
    {
        return $request->getProtocolVersion() === '1.0' || $request->headers->get('Connection') === 'close';
    }
}
