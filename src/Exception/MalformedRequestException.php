<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

final class MalformedRequestException extends \InvalidArgumentException implements ClientInputExceptionInterface
{
}
