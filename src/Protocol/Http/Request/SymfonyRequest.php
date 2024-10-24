<?php

declare(strict_types=1);

namespace Luzrain\WorkermanBundle\Protocol\Http\Request;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Workerman\Connection\ConnectionInterface;

class SymfonyRequest extends Request
{
    private \Workerman\Protocols\Http\Request $rawRequest;

    public ?ConnectionInterface $connection = null;

    public function __construct(private readonly string $buffer)
    {
        $this->rawRequest = new \Workerman\Protocols\Http\Request($buffer);
        parent::__construct(
            $this->rawRequest->get(),
            $this->rawRequest->post(),
            [],
            $this->rawRequest->cookie(),
            [],
            [
                'REQUEST_URI' => $this->rawRequest->uri(),
                'REQUEST_METHOD' => $this->rawRequest->method(),
                'SERVER_PROTOCOL' => $this->rawRequest->protocolVersion(),
            ],
            $this->rawRequest->rawBody(),
        );

        foreach ($this->rawRequest->header() as $name => $value) {
            $this->headers->set($name, $value);
        }

        foreach ($this->rawRequest->file() as $key => $value) {
            $type = $value['type'] ?? 'application/octet-stream';
            $this->files->set(
                $key,
                new UploadedFile($value['tmp_name'], $value['name'], $type === '' ? 'application/octet-stream' : $type),
            );
        }
    }
}
