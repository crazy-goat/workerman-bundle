<?php


namespace Luzrain\WorkermanBundle\Protocol\Http\Request;

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
            [], //@todo
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
    }
}
