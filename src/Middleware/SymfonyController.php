<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Middleware;

use CrazyGoat\WorkermanBundle\DTO\RequestConverter;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

class SymfonyController
{
    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    public function __invoke(Request $request): Response
    {
        $symfonyRequest = RequestConverter::toSymfonyRequest($request);
        $this->kernel->boot();

        $symfonyResponse = $this->kernel->handle($symfonyRequest);
        $symfonyResponse->prepare($symfonyRequest);

        $response = new Response($symfonyResponse->getStatusCode(), $symfonyResponse->headers->all(), $symfonyResponse->getContent());

        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($symfonyRequest, $symfonyResponse);
        }

        return $response;
    }
}
