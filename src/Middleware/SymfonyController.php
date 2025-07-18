<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Middleware;

use CrazyGoat\WorkermanBundle\DTO\RequestConverter;
use CrazyGoat\WorkermanBundle\Http\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Workerman\Protocols\Http\Response;

class SymfonyController
{
    private const FIX_HEADERS = [
        'content-type' => 'Content-Type',
        'connection' => 'Connection',
        'transfer-encoding' => 'Transfer-Encoding',
        'server' => 'Server',
        'content-disposition' => 'Content-Disposition',
        'last-modified' => 'Last-Modified',
    ];

    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    public function __invoke(Request $request): Response
    {
        $symfonyRequest = RequestConverter::toSymfonyRequest($request);
        $this->kernel->boot();

        $symfonyResponse = $this->kernel->handle($symfonyRequest);
        $symfonyResponse->prepare($symfonyRequest);

        $response = new Response(
            $symfonyResponse->getStatusCode(),
            $this->getHeaders($symfonyResponse),
            strval($symfonyResponse->getContent()),
        );

        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($symfonyRequest, $symfonyResponse);
        }

        return $response;
    }

    /** @return array<string, list<string|null>> */
    private function getHeaders(\Symfony\Component\HttpFoundation\Response $symfonyResponse): array
    {
        $headers = $symfonyResponse->headers->all();

        foreach (self::FIX_HEADERS as $fixHeader => $header) {
            if (isset($headers[$fixHeader])) {
                $headers[$header] = $headers[$fixHeader];
                unset($headers[$fixHeader]);
            }
        }

        return $headers;
    }
}
