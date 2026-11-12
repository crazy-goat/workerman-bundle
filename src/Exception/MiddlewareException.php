<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

abstract class MiddlewareException extends \InvalidArgumentException implements WorkermanExceptionInterface
{
}
