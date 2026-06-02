<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategyInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Strategy for converting Symfony BinaryFileResponse to Workerman Response.
 *
 * This handles file downloads properly by using Workerman's native withFile()
 * method, which efficiently streams files without loading them into memory.
 *
 * @see BinaryFileResponse         Depends on private fields: tempFileObject, offset, maxlen, deleteFileAfterSend
 * @see BinaryFileResponseReflector
 */
final readonly class BinaryFileResponseStrategy implements ResponseConverterStrategyInterface
{
    public function __construct(
        private BinaryFileResponseReflector $reflector = new BinaryFileResponseReflector(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(SymfonyResponse $response): bool
    {
        return $response instanceof BinaryFileResponse;
    }

    public function convert(SymfonyResponse $response, array $headers, TcpConnection $connection): WorkermanResponse
    {
        /** @var BinaryFileResponse $response */
        $workermanResponse = new WorkermanResponse(
            $response->getStatusCode(),
            $headers,
        );

        $tempFileObject = $this->reflector->getTempFileObject($response);
        if ($tempFileObject instanceof \SplTempFileObject) {
            $tempFileObject->rewind();
            $content = '';
            while (!$tempFileObject->eof()) {
                $content .= $tempFileObject->fread(8192);
            }
            $workermanResponse->withBody($content);

            return $workermanResponse;
        }

        $file = $response->getFile();
        $offset = $this->reflector->getOffset($response);
        $maxlen = $this->reflector->getMaxlen($response);
        $deleteFileAfterSend = $this->reflector->getDeleteFileAfterSend($response);

        if ($deleteFileAfterSend === true) {
            $filePath = $file->getPathname();

            $workermanResponse->withFile($filePath, $offset ?? 0, $maxlen ?? 0);
            $connection->onClose = $this->createCleanupCallback($filePath, $connection->onClose, $this->logger);

            return $workermanResponse;
        }

        $workermanResponse->withFile(
            $file->getPathname(),
            $offset ?? 0,
            $maxlen ?? 0,
        );

        return $workermanResponse;
    }

    private function createCleanupCallback(string $filePath, mixed $previousOnClose, LoggerInterface $logger): \Closure
    {
        return static function () use ($filePath, $previousOnClose, $logger): void {
            try {
                if (is_callable($previousOnClose)) {
                    $previousOnClose();
                }
            } finally {
                if (is_file($filePath) && !unlink($filePath)) {
                    $logger->warning('Failed to delete temporary file after send', [
                        'path' => $filePath,
                        'error' => error_get_last()['message'] ?? 'Unknown error',
                    ]);
                }
            }
        };
    }
}
