<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\DTO;

use CrazyGoat\WorkermanBundle\Validator\FileUploadValidator;
use Symfony\Component\HttpFoundation\Request;

class RequestConverter
{
    private static ?FileUploadValidator $fileUploadValidator = null;

    public static function toSymfonyRequest(\Workerman\Protocols\Http\Request $rawRequest): Request
    {
        $cookies = $rawRequest->cookie();
        $query = $rawRequest->get();
        // IMPORTANT: Get files BEFORE post() because parsePost() clears the files array
        $files = $rawRequest->file() ?? [];
        $post = $rawRequest->post();

        // Validate file structure to provide clearer error messages
        self::getFileUploadValidator()->validate($files);

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

    private static function getFileUploadValidator(): FileUploadValidator
    {
        if (!self::$fileUploadValidator instanceof FileUploadValidator) {
            self::$fileUploadValidator = new FileUploadValidator();
        }

        return self::$fileUploadValidator;
    }
}
