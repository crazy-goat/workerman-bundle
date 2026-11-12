<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

final readonly class ProcessSnapshot
{
    public function __construct(
        public ?string $state,
        public int $startTime,
        public ?int $uid,
    ) {
    }

    public function isAlive(): bool
    {
        return $this->state !== null && $this->state !== 'Z';
    }
}
