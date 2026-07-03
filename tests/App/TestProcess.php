<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

use CrazyGoat\WorkermanBundle\Attribute\AsProcess;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsProcess(name: 'Test process')]
final readonly class TestProcess
{
    /**
     * Heartbeat interval (seconds) for the status file refresh. The supervisor
     * restarts this worker after each `__invoke()` returns, so ProcessTest can
     * observe the supervisor's restart cycle through a fresh status timestamp.
     * Refreshing the file once per iteration ensures the timestamp stays within
     * the test's "recently alive" window even on slow CI runners (especially
     * macOS where `__invoke()` boot + shutdown + supervisor respawn can exceed
     * 4 seconds), fixing #534.
     */
    private const HEARTBEAT_INTERVAL_SECONDS = 1;

    public function __construct(
        #[Autowire(value: '%kernel.project_dir%/var/process_status.log')]
        private string $statusFile,
    ) {
    }

    public function __invoke(): never
    {
        // Heartbeat loop: stamp the status file every second, then exit so the
        // supervisor can restart the worker. A single one-shot write followed
        // by `exit` worked on Linux because Workerman's boot is fast, but on
        // macOS the boot + shutdown + respawn cycle can exceed the test's 4s
        // recency window before a fresh timestamp is written. Refreshing the
        // file every heartbeat keeps the timestamp inside the window at all
        // times — restoring the supervisor-restart contract that #534 verifies.
        $startedAt = time();
        while (true) {
            file_put_contents($this->statusFile, time());
            $nextTick = $startedAt + self::HEARTBEAT_INTERVAL_SECONDS;
            $remaining = $nextTick - time();
            if ($remaining > 0) {
                sleep($remaining);
            }
            $startedAt = time();
            exit;
        }
    }
}
