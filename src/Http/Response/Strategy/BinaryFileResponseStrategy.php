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
 */
final readonly class BinaryFileResponseStrategy implements ResponseConverterStrategyInterface
{
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

        // Handle SplTempFileObject (in-memory files) - read directly to body
        // SplTempFileObject stores data in memory (default max 2MB, configurable via $max_memory)
        // For larger data, use regular files on disk instead of SplTempFileObject
        $tempFileObject = $this->getTempFileObject($response);
        if ($tempFileObject instanceof \SplTempFileObject) {
            $tempFileObject->rewind();
            $content = '';
            while (!$tempFileObject->eof()) {
                $content .= $tempFileObject->fread(8192);
            }
            $workermanResponse->withBody($content);

            return $workermanResponse;
        }

        // Regular file - use Workerman's efficient file streaming
        $file = $response->getFile();
        $offset = $this->getPrivateProperty($response, 'offset');
        $maxlen = $this->getPrivateProperty($response, 'maxlen');
        $deleteFileAfterSend = $this->getPrivateProperty($response, 'deleteFileAfterSend');

        // If file should be deleted after send, stream it and register cleanup on connection close
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

    /**
     * Get the temp file object from BinaryFileResponse if it exists.
     * Uses reflection to access private property with graceful fallback.
     */
    private function getTempFileObject(BinaryFileResponse $response): ?\SplTempFileObject
    {
        try {
            $reflection = new \ReflectionClass($response);
            $property = $reflection->getProperty('tempFileObject');
            $value = $property->getValue($response);

            return $value instanceof \SplTempFileObject ? $value : null;
        } catch (\ReflectionException) {
            // Property doesn't exist in this Symfony version, assume no temp file
            return null;
        }
    }

    /**
     * Get a private property value from BinaryFileResponse.
     * Uses reflection with graceful fallback if property doesn't exist.
     */
    private function getPrivateProperty(BinaryFileResponse $response, string $propertyName): mixed
    {
        try {
            $reflection = new \ReflectionClass($response);
            $property = $reflection->getProperty($propertyName);

            return $property->getValue($response);
        } catch (\ReflectionException) {
            // Property doesn't exist in this Symfony version, return null
            return null;
        }
    }
}
