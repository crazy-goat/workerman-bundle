<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http;

use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use Workerman\Protocols\Http\Response;

/**
 * @internal
 */
final class MiddlewareDispatcher
{
    /** @var int<0, max> */
    private int $index = 0;

    /**
     * @param MiddlewareInterface[] $middlewares
     * @param callable              $controller
     */
    public function __construct(
        private readonly array $middlewares,
        private readonly mixed $controller,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($this->index >= \count($this->middlewares)) {
            return ($this->controller)($request);
        }

        $i = $this->index;
        ++$this->index;
        try {
            return ($this->middlewares[$i])($request, $this);
        } finally {
            $this->index = $i;
        }
    }
}
