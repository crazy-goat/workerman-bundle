<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\DTO;

use CrazyGoat\WorkermanBundle\Exception\FileUploadValidationException;
use CrazyGoat\WorkermanBundle\Validator\FileUploadValidator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class RequestConverter
{
    /**
     * Security-hardened HTTP method pattern: only uppercase ASCII letters allowed.
     * This is stricter than RFC 7230 (which allows digits and special characters
     * in tokens) to minimise the attack surface for method-based routing bypasses.
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
                \sprintf('Request URI contains control characters: %s', \addcslashes($uri, "\x00..\x1F\x7F")),
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

        // Build server headers from raw HTTP with security hardening
        $server = self::buildServerHeaders($rawRequest, $server);

        // For multipart requests, pass empty content to match PHP-FPM behavior
        // (php://input is not available for multipart - body is consumed during $_POST/$_FILES parsing)
        $content = $isMultipart ? '' : $rawRequest->rawBody();

        return new Request(
            is_array($query) ? $query : [],
            $isFormData && is_array($post) ? $post : [],
            [],
            self::parseCookiesFromServerBag($server),
            $files,
            $server,
            $content,
        );
    }

    /**
     * Build server headers from Workerman request with security hardening.
     *
     * Uses Workerman's parsed headers as the base (includes middleware-added headers),
     * and only falls back to raw header parsing to detect duplicate header values
     * for special handling:
     *
     * - Cookie: multiple header values joined with '; ' per RFC 6265
     * - Host, Content-Length, Authorization: only the first value is used (duplicates discarded)
     * - All other headers: joined with ', ' per RFC 7230
     * - Header values containing control characters are rejected
     *
     * @param array<string, float|int|string> $server
     *
     * @return array<string, float|int|string>
     */
    private static function buildServerHeaders(\Workerman\Protocols\Http\Request $rawRequest, array $server): array
    {
        $workermanHeaders = $rawRequest->header() ?? [];
        $rawHeaders = self::parseRawHeaderLines($rawRequest->rawHead());

        foreach ($workermanHeaders as $name => $value) {
            $nameLower = \strtolower((string) $name);
            $key = 'HTTP_' . \strtoupper(\str_replace('-', '_', $name));

            // Validate header value for control characters
            $stringValue = (string) $value;
            if (\preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $stringValue)) {
                throw new \InvalidArgumentException(
                    \sprintf('Header "%s" contains control characters: "%s"', $nameLower, \addcslashes($stringValue, "\x00..\x1F\x7F")),
                );
            }

            // Check if this header had duplicate values in the raw request
            $originalValues = $rawHeaders[$nameLower] ?? null;
            $hadDuplicates = $originalValues !== null && \count($originalValues) > 1;

            if ($hadDuplicates) {
                $server[$key] = match ($nameLower) {
                    // RFC 6265: multiple Cookie header values must be joined with '; '
                    'cookie' => \implode('; ', $originalValues),
                    // Security-sensitive headers: only the first value is meaningful
                    // Transfer-encoding is also protected against TE/TE smuggling
                    'host', 'content-length', 'authorization', 'transfer-encoding' => $originalValues[0],
                    // Standard HTTP behavior: join with ', ' per RFC 7230
                    default => \implode(', ', $originalValues),
                };
            } else {
                $server[$key] = $stringValue;
            }
        }

        // Content-Type, Content-Length, Content-MD5 use CGI convention (no HTTP_ prefix)
        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'] as $specialHeader) {
            $httpKey = 'HTTP_' . $specialHeader;
            if (isset($server[$httpKey])) {
                $server[$specialHeader] = $server[$httpKey];
                unset($server[$httpKey]);
            }
        }

        return $server;
    }

    /**
     * Parse raw HTTP headers into name => list of values.
     *
     * The request line (first line of the head) is skipped.
     * Header names are returned in lowercase.
     *
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
     * Parse cookies from the server bag's HTTP_COOKIE value.
     *
     * This replaces Workerman's built-in cookie() call to ensure correct
     * handling when duplicate Cookie headers are present (see security issue #217).
     *
     * @param array<string, mixed> $server
     *
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
            $cookies[$name] = $parts[1] ?? '';
        }

        return $cookies;
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
            if (!is_array($value)) {
                throw new FileUploadValidationException(
                    \sprintf(
                        'Malformed file upload data for field "%s": expected array, got %s',
                        $key,
                        \gettype($value),
                    ),
                );
            }

            // Check if this is a single file entry using shared shape recognition
            if (FileUploadValidator::isSingleFileEntry($value)) {
                $type = $value['type'] ?? 'application/octet-stream';
                $error = $value['error'] ?? \UPLOAD_ERR_OK;

                $result[$key] = new UploadedFile(
                    $value['tmp_name'],
                    $value['name'] ?? '',
                    $type === '' ? 'application/octet-stream' : $type,
                    $error,
                    true,
                );
            } else {
                // Nested array - recurse
                $result[$key] = self::processFiles($value);
            }
        }

        return $result;
    }
}
