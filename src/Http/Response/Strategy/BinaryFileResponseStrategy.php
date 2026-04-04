<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategyInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
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

    public function convert(SymfonyResponse $response, array $headers): WorkermanResponse
    {
        /** @var BinaryFileResponse $response */
        $workermanResponse = new WorkermanResponse(
            $response->getStatusCode(),
            $headers,
        );

        // Handle SplTempFileObject (in-memory files) - read content directly
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

        // If file should be deleted after send, we must read it into memory
        // because Workerman's withFile() doesn't support post-send callbacks
        if ($deleteFileAfterSend === true) {
            $filePath = $file->getPathname();
            $content = $this->readFileContent($filePath, $offset ?? 0, $maxlen ?? 0);
            $workermanResponse->withBody($content);

            // Delete the file immediately after reading
            if (is_file($filePath)) {
                unlink($filePath);
            }

            return $workermanResponse;
        }

        $workermanResponse->withFile(
            $file->getPathname(),
            $offset ?? 0,
            $maxlen ?? 0,
        );

        return $workermanResponse;
    }

    /**
     * Read file content with optional offset and length limit.
     * If maxlen is 0, reads the entire file from offset.
     */
    private function readFileContent(string $filePath, int $offset, int $maxlen): string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return '';
        }

        // If maxlen is 0 or negative, read entire file from offset
        $length = $maxlen > 0 ? $maxlen : null;
        $content = file_get_contents($filePath, false, null, $offset, $length);

        return $content !== false ? $content : '';
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
