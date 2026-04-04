<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

class NoResponseStrategyException extends \LogicException implements WorkermanExceptionInterface
{
    public function __construct(string $responseClass)
    {
        parent::__construct(sprintf(
            'No strategy found for response type: %s',
            $responseClass,
        ));
    }
}
