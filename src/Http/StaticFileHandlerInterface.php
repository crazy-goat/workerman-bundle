<?php

declare(strict_types=1);

namespace Luzrain\WorkermanBundle\Http;

interface StaticFileHandlerInterface
{
    public function withRootDirectory(?string $rootDirectory): self;
}
