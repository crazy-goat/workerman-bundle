<?php

declare(strict_types=1);

namespace Luzrain\WorkermanBundle\Protocol\Http\Response;

interface StreamResponseInterface
{
    public function streamContent(): \Generator;
}
