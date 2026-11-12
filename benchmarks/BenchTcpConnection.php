<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Benchmark;

use Workerman\Connection\TcpConnection;

final class BenchTcpConnection extends TcpConnection
{
    public function __construct()
    {
    }

    public function send(mixed $sendBuffer, bool $raw = false): bool
    {
        return true;
    }

    public function close(mixed $data = null, bool $raw = false): void
    {
    }
}
