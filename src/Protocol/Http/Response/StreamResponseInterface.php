<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Protocol\Http\Response;

interface StreamResponseInterface
{
    public function streamContent(): \Generator;
}
