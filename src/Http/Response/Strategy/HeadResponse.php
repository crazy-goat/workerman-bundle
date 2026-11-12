<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use Workerman\Protocols\Http\Response as WorkermanResponse;

final class HeadResponse extends WorkermanResponse
{
    /**
     * @param array<string, string|list<string|null>> $headers
     */
    public function __construct(
        int $status,
        array $headers,
        private readonly int $contentLength,
    ) {
        parent::__construct($status, $headers);
    }

    public function __toString(): string
    {
        $wire = parent::__toString();

        $replaced = preg_replace(
            '/\r\nContent-Length: \d+\r\n\r\n$/',
            "\r\nContent-Length: {$this->contentLength}\r\n\r\n",
            $wire,
        );
        if ($replaced === $wire) {
            $replaced = preg_replace(
                '/\r\nContent-Length: \d+\r\nConnection: keep-alive\r\n\r\n$/',
                "\r\nContent-Length: {$this->contentLength}\r\nConnection: keep-alive\r\n\r\n",
                $wire,
            );
        }

        return $replaced ?? $wire;
    }
}
