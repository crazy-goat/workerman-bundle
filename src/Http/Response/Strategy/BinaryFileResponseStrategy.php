<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategyInterface;
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
            $connection->onClose = $this->createCleanupCallback($filePath);

            return $workermanResponse;
        }

        $workermanResponse->withFile(
            $file->getPathname(),
            $offset ?? 0,
            $maxlen ?? 0,
        );

        return $workermanResponse;
    }

    private function createCleanupCallback(string $filePath): \Closure
    {
        return static function () use ($filePath): void {
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        };
    }
}
