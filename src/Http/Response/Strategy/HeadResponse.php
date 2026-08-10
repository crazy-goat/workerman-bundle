<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Workerman response for a HEAD request that carries an application-provided
 * Content-Length (the length the corresponding GET would produce, RFC 9110
 * §9.3.2). Workerman's Response::__toString() always computes Content-Length
 * from the body — 0 for the empty HEAD body — and would emit a duplicate
 * header if one was supplied, so this subclass rewrites the computed value
 * at serialization time (issue #643).
 */
final class HeadResponse extends WorkermanResponse
{
    /**
     * @param array<string, string|list<string|null>> $headers Headers without
     *                                                          Content-Length (carried separately)
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

        // Workerman appends the computed body length as a header line;
        // replace that value with the application-provided one so the HEAD
        // response carries exactly one, correct Content-Length. Two
        // serialization shapes exist: the regular branch emits
        // "Content-Length: N" last (just before the terminating empty line),
        // while the empty-headers fast path appends "Connection: keep-alive"
        // after it. When neither tail matches (e.g. the text/event-stream
        // branch omits Content-Length), keep the transport output unchanged.
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
