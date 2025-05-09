<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

use CrazyGoat\WorkermanBundle\Attribute\AsTask;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTask(name: 'Test task', schedule: '1 second')]
final class TestTask
{
    public function __construct(
        #[Autowire(value: '%kernel.project_dir%/var/task_status.log')]
        private readonly string $statusFile,
    ) {
    }

    public function __invoke(): void
    {
        file_put_contents($this->statusFile, time());
    }
}
