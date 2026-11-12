<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http;

/**
 * @see \Workerman\Protocols\Http\Request
 * @see \CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface
 */
class Request extends \Workerman\Protocols\Http\Request
{
    private int $addedHeaderCount = 0;

    public function setHeader(string $name, string $value): self
    {
        if (!isset($this->data['headers'])) {
            $this->parseHeaders();
        }

        $name = strtolower($name);
        if (!\array_key_exists($name, $this->data['headers'])) {
            ++$this->addedHeaderCount;
        }
        $this->data['headers'][$name] = $value;

        return $this;
    }

    public function resetHeaders(): void
    {
        $this->parseHeaders();
        $this->addedHeaderCount = 0;
    }

    public function addedHeaderCount(): int
    {
        return $this->addedHeaderCount;
    }

    /**
     * @deprecated
     */
    public function withHeader(string $name, string $value): self
    {
        trigger_error(
            \sprintf(
                'Since crazy-goat/workerman-bundle 0.23.0: %s::withHeader() is deprecated, use setHeader() instead. Will be removed in 1.0.',
                self::class,
            ),
            \E_USER_DEPRECATED,
        );

        return $this->setHeader($name, $value);
    }
}
