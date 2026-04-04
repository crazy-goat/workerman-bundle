<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Exception thrown when an invalid cron expression is provided.
 */
final class InvalidCronExpressionException extends SchedulerException
{
}
