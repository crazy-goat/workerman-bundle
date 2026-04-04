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

        // Handle SplTempFileObject (in-memory files) - write to temp file for streaming
        $tempFileObject = $this->getTempFileObject($response);
        if ($tempFileObject instanceof \SplTempFileObject) {
            $tempFilePath = $this->writeTempFileObjectToDisk($tempFileObject);

            if ($tempFilePath !== null) {
                // Use Workerman's efficient file streaming
                $workermanResponse->withFile($tempFilePath, 0, 0);

                // Register cleanup to delete temp file after request
                register_shutdown_function(static function () use ($tempFilePath): void {
                    if (is_file($tempFilePath)) {
                        unlink($tempFilePath);
                    }
                });

                return $workermanResponse;
            }

            // Fallback: read into memory if temp file creation fails
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
     * Write SplTempFileObject content to a physical temp file for streaming.
     * Returns the temp file path or null on failure.
     */
    private function writeTempFileObjectToDisk(\SplTempFileObject $tempFileObject): ?string
    {
        $tempFilePath = tempnam(sys_get_temp_dir(), 'workerman_bundle_');
        if ($tempFilePath === false) {
            return null;
        }

        $tempFileObject->rewind();
        $destHandle = fopen($tempFilePath, 'wb');
        if ($destHandle === false) {
            unlink($tempFilePath);
            return null;
        }

        while (!$tempFileObject->eof()) {
            $chunk = $tempFileObject->fread(8192);
            if ($chunk === false || fwrite($destHandle, $chunk) === false) {
                fclose($destHandle);
                unlink($tempFilePath);
                return null;
            }
        }

        fclose($destHandle);

        return $tempFilePath;
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
