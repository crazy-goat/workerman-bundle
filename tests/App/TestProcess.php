<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

use CrazyGoat\WorkermanBundle\Attribute\AsProcess;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsProcess(name: 'Test process')]
final readonly class TestProcess
{
    private const HEARTBEAT_INTERVAL_SECONDS = 1;

    public function __construct(
        #[Autowire(value: '%kernel.project_dir%/var/process_status.log')]
        private string $statusFile,
    ) {
    }

    public function __invoke(): void
    {
        $startedAt = time();
        while (true) {
            file_put_contents($this->statusFile, time());
            $nextTick = $startedAt + self::HEARTBEAT_INTERVAL_SECONDS;
            $remaining = $nextTick - time();
            if ($remaining > 0) {
                sleep($remaining);
            }
            $startedAt = time();
            return;
        }
    }
}
