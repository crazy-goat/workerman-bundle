<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\DTO;

use CrazyGoat\WorkermanBundle\Exception\FileUploadValidationException;
use CrazyGoat\WorkermanBundle\Exception\MalformedRequestException;
use CrazyGoat\WorkermanBundle\Validator\FileUploadValidator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Workerman\Worker;

final class RequestConverter
{
    private const METHOD_REGEX = '/^[A-Z]+$/';
    private const MAX_METHOD_LENGTH = 32;

    private const HEADER_VALUE_CONTROL_CHARS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0A\x0B\x0C\x0D\x0E\x0F"
        . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    private const MAX_LOGGED_UNDERSCORE_HEADERS = 64;

    /** @var array<string, true> */
    private static array $loggedUnderscoreHeaders = [];

    private static bool $underscoreHeaderLogSuppressed = false;

    private static function validateUri(string $uri): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $uri)) {
            throw new MalformedRequestException(
                \sprintf('Request URI contains control characters: %s', \addcslashes($uri, "\x00..\x1F\x7F")),
            );
        }
    }

    private static function validateMethod(string $method): void
    {
        if (\strlen($method) > self::MAX_METHOD_LENGTH) {
            throw new MalformedRequestException(
                \sprintf('HTTP method exceeds maximum length of %d: %s', self::MAX_METHOD_LENGTH, $method),
            );
        }

        if (preg_match(self::METHOD_REGEX, $method) !== 1) {
            throw new MalformedRequestException(
                \sprintf('HTTP method contains invalid characters: %s', $method),
            );
        }
    }

    public static function toSymfonyRequest(\Workerman\Protocols\Http\Request $rawRequest): Request
    {
        $query = $rawRequest->get();
        $files = $rawRequest->file() ?? [];
        $post = $rawRequest->post();

        if ($files !== []) {
            $files = self::processFiles($files);
        }

        $uri = $rawRequest->uri();
        $method = $rawRequest->method();
        self::validateUri($uri);
        self::validateMethod($method);

        $requestTimeFloat = microtime(true);
        $isHttps = isset($rawRequest->connection) && $rawRequest->connection->transport === 'ssl';
        $formData = self::detectFormData((string) $rawRequest->header('content-type', ''));

        $server = self::buildServerBag($rawRequest, $uri, $method, $isHttps, $requestTimeFloat);
        $server = self::buildServerHeaders($rawRequest, $server);

        $content = $formData['isMultipart'] ? '' : $rawRequest->rawBody();

        return new Request(
            is_array($query) ? $query : [],
            $formData['isFormData'] && is_array($post) ? $post : [],
            [],
            self::parseCookiesFromServerBag($server),
            $files,
            $server,
            $content,
        );
    }

    /**
     * @return array{isMultipart: bool, isFormData: bool}
     */
    private static function detectFormData(string $contentType): array
    {
        $contentType = strtolower($contentType);
        $isFormUrlEncoded = str_starts_with($contentType, 'application/x-www-form-urlencoded');
        $isMultipart = str_starts_with($contentType, 'multipart/form-data');

        return [
            'isMultipart' => $isMultipart,
            'isFormData' => $isFormUrlEncoded || $isMultipart,
        ];
    }

    /**
     * @return array<string, float|int|string>
     */
    private static function buildServerBag(
        \Workerman\Protocols\Http\Request $rawRequest,
        string $uri,
        string $method,
        bool $isHttps,
        float $requestTimeFloat,
    ): array {
        $server = [
            'REQUEST_URI' => $uri,
            'REQUEST_METHOD' => $method,
            'SERVER_PROTOCOL' => 'HTTP/' . $rawRequest->protocolVersion(),
            'REMOTE_ADDR' => $rawRequest->connection?->getRemoteIp() ?? '127.0.0.1',
            'REMOTE_PORT' => $rawRequest->connection?->getRemotePort() ?? 0,
            'SERVER_PORT' => $rawRequest->connection?->getLocalPort() ?? ($isHttps ? 443 : 80),
            'SERVER_NAME' => $rawRequest->connection?->getLocalIp() ?? 'localhost',
            'QUERY_STRING' => $rawRequest->queryString(),
            'REQUEST_TIME' => (int) $requestTimeFloat,
            'REQUEST_TIME_FLOAT' => $requestTimeFloat,
        ];

        if ($isHttps) {
            $server['HTTPS'] = 'on';
        }

        return $server;
    }

    /**
     * @param array<string, float|int|string> $server
     * @return array<string, float|int|string>
     */
    private static function buildServerHeaders(\Workerman\Protocols\Http\Request $rawRequest, array $server): array
    {
        $workermanHeaders = $rawRequest->header() ?? [];
        $rawHeaders = self::rawHeadMayHaveDuplicates($rawRequest, $workermanHeaders)
            ? self::parseRawHeaderLines($rawRequest->rawHead())
            : [];

        foreach ($workermanHeaders as $name => $value) {
            $name = (string) $name;
            $nameLower = \strtolower($name);

            $stringValue = (string) $value;
            if (\strcspn($stringValue, self::HEADER_VALUE_CONTROL_CHARS) < \strlen($stringValue)) {
                throw new MalformedRequestException(
                    \sprintf('Header "%s" contains control characters: "%s"', $nameLower, \addcslashes($stringValue, "\x00..\x1F\x7F")),
                );
            }

            if (\str_contains($name, '_')) {
                self::logDroppedUnderscoreHeader($name);
                continue;
            }

            if (\strpbrk($name, " \t") !== false) {
                continue;
            }

            $key = 'HTTP_' . \strtoupper(\str_replace('-', '_', $name));

            $originalValues = $rawHeaders[$nameLower] ?? null;
            $hadDuplicates = $originalValues !== null && \count($originalValues) > 1;

            if ($hadDuplicates) {
                $server[$key] = match ($nameLower) {
                    'cookie' => \implode('; ', $originalValues),
                    'host', 'content-length', 'authorization', 'transfer-encoding' => $originalValues[0],
                    default => \implode(', ', $originalValues),
                };
            } else {
                $server[$key] = $stringValue;
            }
        }

        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'] as $specialHeader) {
            $httpKey = 'HTTP_' . $specialHeader;
            if (isset($server[$httpKey])) {
                $server[$specialHeader] = $server[$httpKey];
                unset($server[$httpKey]);
            }
        }

        return $server;
    }

    private static function logDroppedUnderscoreHeader(string $name): void
    {
        $nameLower = \strtolower($name);
        if (isset(self::$loggedUnderscoreHeaders[$nameLower])) {
            return;
        }

        if (\count(self::$loggedUnderscoreHeaders) >= self::MAX_LOGGED_UNDERSCORE_HEADERS) {
            if (!self::$underscoreHeaderLogSuppressed) {
                self::$underscoreHeaderLogSuppressed = true;
                self::writeDroppedHeaderLog('[warning] Reached log limit for dropped underscore header names; further names are suppressed');
            }

            return;
        }

        self::$loggedUnderscoreHeaders[$nameLower] = true;
        self::writeDroppedHeaderLog(\sprintf(
            '[warning] Dropped HTTP header "%s": header names containing underscores are not forwarded to Symfony',
            \addcslashes($name, "\0..\37\177"),
        ));
    }

    private static function writeDroppedHeaderLog(string $message): void
    {
        if (Worker::$logFile === '') {
            \error_log($message);

            return;
        }

        try {
            Worker::log($message);
        } catch (\Throwable) {
            \error_log($message);
        }
    }

    /**
     * @param array<array-key, mixed> $workermanHeaders
     */
    private static function rawHeadMayHaveDuplicates(
        \Workerman\Protocols\Http\Request $rawRequest,
        array $workermanHeaders,
    ): bool {
        $addedHeaders = $rawRequest instanceof \CrazyGoat\WorkermanBundle\Http\Request
            ? $rawRequest->addedHeaderCount()
            : 0;

        if (\substr_count($rawRequest->rawHead(), "\r\n") !== \count($workermanHeaders) - $addedHeaders) {
            return true;
        }

        foreach ($workermanHeaders as $name => $value) {
            if ((string) $name !== \trim((string) $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function parseRawHeaderLines(string $rawHead): array
    {
        $headers = [];
        $lines = \explode("\r\n", $rawHead);
        \array_shift($lines);

        foreach ($lines as $line) {
            if ($line === '' || $line === "\r") {
                continue;
            }
            if (\str_contains($line, ':')) {
                [$name, $value] = \explode(':', $line, 2);
                $nameLower = \strtolower(\trim($name));
                $value = \ltrim($value);
                $headers[$nameLower][] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function parseCookiesFromServerBag(array $server): array
    {
        $cookieHeader = $server['HTTP_COOKIE'] ?? '';
        if ($cookieHeader === '') {
            return [];
        }

        $cookies = [];
        $pairs = \explode(';', (string) $cookieHeader);

        foreach ($pairs as $pair) {
            $pair = \trim($pair);
            if ($pair === '') {
                continue;
            }
            $parts = \explode('=', $pair, 2);
            $name = \trim($parts[0]);
            if ($name === '') {
                continue;
            }
            $cookies[$name] = \rawurldecode($parts[1] ?? '');
        }

        return $cookies;
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     * @throws FileUploadValidationException
     */
    private static function processFiles(array $files): array
    {
        $result = [];
        foreach ($files as $key => $value) {
            $result[$key] = self::processFileNode((string) $key, $value);
        }

        return $result;
    }

    /**
     * @throws FileUploadValidationException
     */
    private static function processFileNode(string $fieldName, mixed $value): mixed
    {
        if (!is_array($value)) {
            throw FileUploadValidator::expectedArrayError($fieldName, $value);
        }

        if (FileUploadValidator::isFileList($value)) {
            $list = [];
            foreach ($value as $index => $entry) {
                $entryFieldName = $fieldName . '[' . $index . ']';
                if (!is_array($entry)) {
                    throw FileUploadValidator::expectedArrayError($entryFieldName, $entry);
                }
                $list[$index] = self::processFileEntry($entryFieldName, $entry);
            }

            return $list;
        }

        if (FileUploadValidator::isSingleFileEntry($value)) {
            return self::processFileEntry($fieldName, $value);
        }

        $nested = [];
        foreach ($value as $subKey => $subValue) {
            $nestedFieldName = $fieldName . '[' . $subKey . ']';
            if (!is_array($subValue)) {
                throw FileUploadValidator::expectedArrayError($nestedFieldName, $subValue);
            }

            if (FileUploadValidator::isFileList($subValue)) {
                $list = [];
                foreach ($subValue as $index => $entry) {
                    $entryFieldName = $nestedFieldName . '[' . $index . ']';
                    if (!is_array($entry)) {
                        throw FileUploadValidator::expectedArrayError($entryFieldName, $entry);
                    }
                    $list[$index] = self::processFileEntry($entryFieldName, $entry);
                }
                $nested[$subKey] = $list;
            } elseif (FileUploadValidator::isSingleFileEntry($subValue)) {
                $nested[$subKey] = self::processFileEntry($nestedFieldName, $subValue);
            } else {
                throw FileUploadValidator::unrecognizedStructureError($nestedFieldName, $subValue);
            }
        }

        return $nested;
    }

    /**
     * @param array<string, mixed> $file
     * @throws FileUploadValidationException
     */
    private static function processFileEntry(string $fieldName, array $file): UploadedFile
    {
        FileUploadValidator::assertRequiredFields($fieldName, $file);

        $type = $file['type'] ?? 'application/octet-stream';
        $error = $file['error'] ?? \UPLOAD_ERR_OK;

        return new UploadedFile(
            $file['tmp_name'],
            $file['name'] ?? '',
            $type === '' ? 'application/octet-stream' : $type,
            $error,
            true,
        );
    }
}
