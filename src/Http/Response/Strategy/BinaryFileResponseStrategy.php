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
        if (!$response instanceof BinaryFileResponse) {
            throw new \InvalidArgumentException('Expected BinaryFileResponse, got ' . $response::class);
        }

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

        $workermanResponse->withFile(
            $file->getPathname(),
            $offset ?? 0,
            $maxlen ?? 0,
        );

        return $workermanResponse;
    }

    /**
     * Get the temp file object from BinaryFileResponse if it exists.
     * Uses reflection to access private property.
     */
    private function getTempFileObject(BinaryFileResponse $response): ?\SplTempFileObject
    {
        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('tempFileObject');

        $value = $property->getValue($response);

        return $value instanceof \SplTempFileObject ? $value : null;
    }

    /**
     * Get a private property value from BinaryFileResponse.
     */
    private function getPrivateProperty(BinaryFileResponse $response, string $propertyName): mixed
    {
        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty($propertyName);

        return $property->getValue($response);
    }
}
