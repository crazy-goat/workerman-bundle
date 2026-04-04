<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\DTO;

use CrazyGoat\WorkermanBundle\Validator\FileUploadValidator;
use Symfony\Component\HttpFoundation\Request;

class RequestConverter
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
        $server = [
            'REQUEST_URI' => $rawRequest->uri(),
            'REQUEST_METHOD' => $rawRequest->method(),
            'SERVER_PROTOCOL' => 'HTTP/' . $rawRequest->protocolVersion(),
        ];

        // Convert headers to HTTP_* format for ServerBag
        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $server[$key] = $value;
        }

        // Content-Type, Content-Length, Content-MD5 use CGI convention (no HTTP_ prefix)
        if (isset($server['HTTP_CONTENT_TYPE'])) {
            $server['CONTENT_TYPE'] = $server['HTTP_CONTENT_TYPE'];
            unset($server['HTTP_CONTENT_TYPE']);
        }
        if (isset($server['HTTP_CONTENT_LENGTH'])) {
            $server['CONTENT_LENGTH'] = $server['HTTP_CONTENT_LENGTH'];
            unset($server['HTTP_CONTENT_LENGTH']);
        }
        if (isset($server['HTTP_CONTENT_MD5'])) {
            $server['CONTENT_MD5'] = $server['HTTP_CONTENT_MD5'];
            unset($server['HTTP_CONTENT_MD5']);
        }

        $request = new Request(
            is_array($query) ? $query : [],
            $isFormData && is_array($post) ? $post : [],
            [],
            is_array($cookies) ? $cookies : [],
            $files,
            $server,
            $rawRequest->rawBody(),
        );

        return $request;
    }
}
