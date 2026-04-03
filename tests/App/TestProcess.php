<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

use CrazyGoat\WorkermanBundle\Attribute\AsProcess;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsProcess(name: 'Test process')]
final readonly class TestProcess
{
    public function __construct(
        #[Autowire(value: '%kernel.project_dir%/var/process_status.log')]
        private string $statusFile,
    ) {
    }

    public function __invoke(): never
    {
        file_put_contents($this->statusFile, time());
        sleep(1);
        exit;
    }
}
