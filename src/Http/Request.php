<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http;

class Request extends \Workerman\Protocols\Http\Request
{
    public function setHeader(string $name, string $value): self
    {
        if (!isset($this->data['headers'])) {
            $this->parseHeaders();
        }

        $name = strtolower($name);
        $this->data['headers'][$name] = $value;

        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        return $this->setHeader($name, $value);
    }
}
