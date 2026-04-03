<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\DTO;

use Symfony\Component\HttpFoundation\Request;

class RequestConverter
{
    public static function toSymfonyRequest(\Workerman\Protocols\Http\Request $rawRequest): Request
    {
        $cookies = $rawRequest->cookie();
        $query = $rawRequest->get();
        $post = $rawRequest->post();
        $files = $rawRequest->file() ?? [];

        // Only populate POST bag for form-encoded content types
        // JSON and other content types should leave POST bag empty (like PHP-FPM)
        $contentType = $rawRequest->header('content-type', '');
        $isFormData = preg_match('/^(application\/x-www-form-urlencoded|multipart\/form-data)\b/i', (string) $contentType);

        $request = new Request(
            is_array($query) ? $query : [],
            $isFormData && is_array($post) ? $post : [],
            [],
            is_array($cookies) ? $cookies : [],
            $files,
            [
                'REQUEST_URI' => $rawRequest->uri(),
                'REQUEST_METHOD' => $rawRequest->method(),
                'SERVER_PROTOCOL' => $rawRequest->protocolVersion(),
            ],
            $rawRequest->rawBody(),
        );

        $headers = $rawRequest->header();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                $request->headers->set($name, $value);
            }
        }

        return $request;
    }
}
