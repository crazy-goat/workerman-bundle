<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Marker interface for all WorkermanBundle exceptions.
 *
 * This interface enables catching all bundle-originated exceptions
 * with a single catch clause, regardless of their parent class.
 */
interface WorkermanExceptionInterface
{
}
