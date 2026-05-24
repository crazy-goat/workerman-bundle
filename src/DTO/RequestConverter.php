<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\DTO;

use CrazyGoat\WorkermanBundle\Validator\FileUploadValidator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class RequestConverter
{
    /**
     * RFC 7230 token pattern for HTTP methods: one or more uppercase ASCII letters.
     */
    private const METHOD_REGEX = '/^[A-Z]+$/';
    private const MAX_METHOD_LENGTH = 32;

    /**
     * Validates that a URI does not contain control characters.
     *
     * Control characters (\x00-\x1F, \x7F) in the URI enable log forging,
     * response splitting, and bypass of URI-based access checks.
     */
    private static function validateUri(string $uri): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $uri)) {
            throw new \InvalidArgumentException(
                \sprintf('Request URI contains control characters: %s', $uri),
            );
        }
    }

    /**
     * Validates that an HTTP method conforms to RFC 7230 token format.
     *
     * Only uppercase ASCII letters are allowed. Length is limited to prevent
     * abuse through oversized method tokens.
     */
    private static function validateMethod(string $method): void
    {
        if (\strlen($method) > self::MAX_METHOD_LENGTH) {
            throw new \InvalidArgumentException(
                \sprintf('HTTP method exceeds maximum length of %d: %s', self::MAX_METHOD_LENGTH, $method),
            );
        }

        if (preg_match(self::METHOD_REGEX, $method) !== 1) {
            throw new \InvalidArgumentException(
                \sprintf('HTTP method contains invalid characters: %s', $method),
            );
        }
    }

    public static function toSymfonyRequest(\Workerman\Protocols\Http\Request $rawRequest): Request
    {
        $cookies = $rawRequest->cookie();
        $query = $rawRequest->get();
        // IMPORTANT: Get files BEFORE post() because parsePost() clears the files array
        $files = $rawRequest->file() ?? [];
        $post = $rawRequest->post();

        // Validate file structure to provide clearer error messages
        FileUploadValidator::validate($files);

        // Convert Workerman's $_FILES-style arrays to UploadedFile objects
        $files = self::processFiles($files);

        // Only populate POST bag for form-encoded content types
        // JSON and other content types should leave POST bag empty (like PHP-FPM)
        $contentType = strtolower((string) $rawRequest->header('content-type', ''));
        $isFormUrlEncoded = str_starts_with($contentType, 'application/x-www-form-urlencoded');
        $isMultipart = str_starts_with($contentType, 'multipart/form-data');
        $isFormData = $isFormUrlEncoded || $isMultipart;

        // Build server bag with HTTP_* headers (CGI convention)
        $headers = $rawRequest->header() ?? [];

        // Detect HTTPS from Workerman's SSL transport (configured via https:// listen address)
        $isHttps = isset($rawRequest->connection) && $rawRequest->connection->transport === 'ssl';

        $requestTimeFloat = microtime(true);

        // Validate URI and method before propagating to Symfony
        $uri = $rawRequest->uri();
        $method = $rawRequest->method();
        self::validateUri($uri);
        self::validateMethod($method);

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

        // Convert headers to HTTP_* format for ServerBag
        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            // Handle repeated headers (Workerman returns arrays for multiple values)
            $server[$key] = is_array($value) ? implode(', ', $value) : $value;
        }

        // Content-Type, Content-Length, Content-MD5 use CGI convention (no HTTP_ prefix)
        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'] as $specialHeader) {
            $httpKey = 'HTTP_' . $specialHeader;
            if (isset($server[$httpKey])) {
                $server[$specialHeader] = $server[$httpKey];
                unset($server[$httpKey]);
            }
        }

        // For multipart requests, pass empty content to match PHP-FPM behavior
        // (php://input is not available for multipart - body is consumed during $_POST/$_FILES parsing)
        $content = $isMultipart ? '' : $rawRequest->rawBody();

        return new Request(
            is_array($query) ? $query : [],
            $isFormData && is_array($post) ? $post : [],
            [],
            is_array($cookies) ? $cookies : [],
            $files,
            $server,
            $content,
        );
    }

    /**
     * Recursively convert Workerman's $_FILES-style arrays to UploadedFile objects.
     *
     * Workerman returns nested arrays for multiple file uploads (e.g., files[] or documents[0]),
     * but Symfony's Request expects UploadedFile objects in the files ParameterBag.
     *
     * @param array<string, mixed> $files
     *
     * @return array<string, mixed>
     */
    private static function processFiles(array $files): array
    {
        $result = [];
        foreach ($files as $key => $value) {
            if (is_array($value)) {
                // Check if this is a single file structure (has 'tmp_name' key)
                if (array_key_exists('tmp_name', $value)) {
                    $type = $value['type'] ?? 'application/octet-stream';
                    $error = $value['error'] ?? \UPLOAD_ERR_OK;

                    $result[$key] = new UploadedFile(
                        $value['tmp_name'],
                        $value['name'] ?? '',
                        $type === '' ? 'application/octet-stream' : $type,
                        $error,
                        true, // test mode: files are already moved to temp dir by Workerman
                    );
                } else {
                    // Nested array - recurse
                    $result[$key] = self::processFiles($value);
                }
            }
        }

        return $result;
    }
}
