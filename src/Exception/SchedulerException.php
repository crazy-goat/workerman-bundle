<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

abstract class SchedulerException extends \InvalidArgumentException implements WorkermanExceptionInterface
{
}
