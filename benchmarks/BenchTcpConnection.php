<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Benchmark;

use Workerman\Connection\TcpConnection;

/**
 * Minimal TcpConnection stub for benchmarking — avoids socket operations
 * and provides no-op send/close implementations.
 */
final class BenchTcpConnection extends TcpConnection
{
    public function __construct()
    {
        // Avoid parent constructor socket operations
    }

    public function send(mixed $sendBuffer, bool $raw = false): bool
    {
        return true;
    }

    public function close(mixed $data = null, bool $raw = false): void
    {
    }
}
