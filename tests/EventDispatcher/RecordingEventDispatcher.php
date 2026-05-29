<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\EventDispatcher;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->events[] = $event;

        return $event;
    }
}
