<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\DTO;

use CrazyGoat\WorkermanBundle\Validator\FileUploadValidator;
use Symfony\Component\HttpFoundation\Request;

final class RequestConverter
{
    public static function toSymfonyRequest(\Workerman\Protocols\Http\Request $rawRequest): Request
    {
        $cookies = $rawRequest->cookie();
        $query = $rawRequest->get();
        // IMPORTANT: Get files BEFORE post() because parsePost() clears the files array
        $files = $rawRequest->file() ?? [];
        $post = $rawRequest->post();

        // Validate file structure to provide clearer error messages
        FileUploadValidator::validate($files);

        // Only populate POST bag for form-encoded content types
        // JSON and other content types should leave POST bag empty (like PHP-FPM)
        $contentType = $rawRequest->header('content-type', '');
        $isFormData = preg_match('/^(application\/x-www-form-urlencoded|multipart\/form-data)\b/i', (string) $contentType);

        // Build server bag with HTTP_* headers (CGI convention)
        $headers = $rawRequest->header() ?? [];
        // Fallback to 127.0.0.1:0 for unit test scenarios where connection is null.
        // In production, connection should always be present.

        // Detect HTTPS from connection port or X-Forwarded-Proto header
        $localPort = $rawRequest->connection?->getLocalPort();
        $forwardedProto = strtolower((string) $rawRequest->header('x-forwarded-proto', ''));
        $isHttps = $localPort === 443 || $forwardedProto === 'https';

        $requestTimeFloat = microtime(true);
        $server = [
            'REQUEST_URI' => $rawRequest->uri(),
            'REQUEST_METHOD' => $rawRequest->method(),
            'SERVER_PROTOCOL' => 'HTTP/' . $rawRequest->protocolVersion(),
            'REMOTE_ADDR' => $rawRequest->connection?->getRemoteIp() ?? '127.0.0.1',
            'REMOTE_PORT' => $rawRequest->connection?->getRemotePort() ?? 0,
            'SERVER_PORT' => $localPort ?? ($isHttps ? 443 : 80),
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

        return new Request(
            is_array($query) ? $query : [],
            $isFormData && is_array($post) ? $post : [],
            [],
            is_array($cookies) ? $cookies : [],
            $files,
            $server,
            $rawRequest->rawBody(),
        );
    }
}
