<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

use CrazyGoat\WorkermanBundle\Attribute\AsProcess;

#[AsProcess(name: 'Test error process')]
final readonly class TestErrorProcess
{
    public function __invoke(): never
    {
        throw new \RuntimeException('Test error process intentionally throws');
    }
}
