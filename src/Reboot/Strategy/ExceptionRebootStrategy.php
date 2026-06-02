<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\Strategy;

use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ExceptionRebootStrategy implements RebootStrategyInterface
{
    private bool $shouldReboot = false;

    /**
     * @param array<class-string> $allowedExceptions
     */
    public function __construct(private readonly array $allowedExceptions = [])
    {
    }

    public function onException(ExceptionEvent $event): void
    {
        if ($event->getRequestType() !== HttpKernelInterface::MAIN_REQUEST) {
            return;
        }

        foreach ($this->allowedExceptions as $allowedExceptionClass) {
            if ($event->getThrowable() instanceof $allowedExceptionClass) {
                return;
            }
        }

        $this->shouldReboot = true;
    }

    public function shouldReboot(): bool
    {
        $result = $this->shouldReboot;
        $this->shouldReboot = false;

        return $result;
    }

    public function needsPeakMemory(): bool
    {
        return false;
    }
}
