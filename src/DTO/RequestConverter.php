<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\DTO;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

class RequestConverter
{
    public static function toSymfonyRequest(\Workerman\Protocols\Http\Request $rawRequest): Request
    {
        $cookies = $rawRequest->cookie();
        $query = $rawRequest->get();
        $post = $rawRequest->post();

        $request = new Request(
            is_array($query) ? $query : [],
            is_array($post) ? $post : [],
            [],
            is_array($cookies) ? $cookies : [],
            [],
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

        foreach ($rawRequest->file() ?? [] as $key => $value) {
            $type = $value['type'] ?? 'application/octet-stream';
            $request->files->set(
                $key,
                new UploadedFile($value['tmp_name'], $value['name'], $type === '' ? 'application/octet-stream' : $type),
            );
        }

        return $request;
    }
}
