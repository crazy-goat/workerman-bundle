<?php

declare(strict_types=1);

namespace Luzrain\WorkermanBundle\Http;

use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Workerman\Protocols\Http\Request;

final class WorkermanHttpMessageFactory
{
    public function __construct(
        private readonly ServerRequestFactoryInterface $serverRequestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly UploadedFileFactoryInterface $uploadedFileFactory,
    ) {
    }

    public function createRequest(Request $workermanRequest): ServerRequestInterface
    {
        $psrRequest = $this->serverRequestFactory->createServerRequest(
            method: $workermanRequest->method(),
            uri: $workermanRequest->uri(),
            serverParams: $_SERVER + [
                'REMOTE_ADDR' => $workermanRequest->connection->getRemoteIp(),
            ],
        );

        if (is_array($workermanRequest->header())) {
            foreach ($workermanRequest->header() as $name => $value) {
                $psrRequest = $psrRequest->withHeader($name, $value);
            }
        }
        $cookies = $workermanRequest->cookie();

        return $psrRequest
            ->withProtocolVersion($workermanRequest->protocolVersion())
            ->withCookieParams(is_array($cookies) ? $cookies : [])
            ->withQueryParams($workermanRequest->get())
            ->withParsedBody($workermanRequest->post() ?? [])
            ->withUploadedFiles($this->normalizeFiles($workermanRequest->file() ?? []))
            ->withBody($this->streamFactory->createStream($workermanRequest->rawBody()))
        ;
    }

    /**
     * @param mixed[] $files
     *
     * @return mixed[]
     */
    private function normalizeFiles(array $files): array
    {
        $normalized = [];
        foreach ($files as $key => $value) {
            if (is_array($value) && isset($value['tmp_name'])) {
                $normalized[$key] = $this->createUploadedFileFromSpec($value);
            } elseif (is_array($value)) {
                $normalized[$key] = $this->normalizeFiles($value);
            }
        }

        return $normalized;
    }

    /**
     * @param mixed[] $value
     *
     * @return list<UploadedFileInterface>|UploadedFileInterface
     */
    private function createUploadedFileFromSpec(array $value): array|UploadedFileInterface
    {
        if (is_array($value['tmp_name'])) {
            return $this->normalizeNestedFileSpec($value);
        }

        return $this->uploadedFileFactory->createUploadedFile(
            stream: $this->streamFactory->createStreamFromFile($value['tmp_name']),
            size: (int) $value['size'],
            error: (int) $value['error'],
            clientFilename: $value['name'],
            clientMediaType: $value['type'],
        );
    }

    /**
     * @param mixed[] $files
     *
     * @return mixed[]
     */
    private function normalizeNestedFileSpec(array $files = []): array
    {
        $normalizedFiles = [];
        foreach (array_keys($files['tmp_name']) as $key) {
            $normalizedFiles[$key] = $this->createUploadedFileFromSpec([
                'tmp_name' => $files['tmp_name'][$key],
                'size' => $files['size'][$key],
                'error' => $files['error'][$key],
                'name' => $files['name'][$key],
                'type' => $files['type'][$key],
            ]);
        }

        return $normalizedFiles;
    }
}
