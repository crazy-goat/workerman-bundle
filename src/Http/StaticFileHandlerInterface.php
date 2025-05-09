<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http;

interface StaticFileHandlerInterface
{
    public function withRootDirectory(?string $rootDirectory): self;
}
