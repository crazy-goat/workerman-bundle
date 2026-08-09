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
     *
     * The method RETURNS when the heartbeat completes instead of calling exit()
     * itself: the supervisor's termination path (SupervisorWorker ->
     * ProcessTerminator) then runs, which on grpc hosts uses SIGKILL to bypass
     * the extension's hanging shutdown handler. A self-called exit() would hang
     * the worker forever on grpc hosts (no restart, stale markers).
     */
    private const HEARTBEAT_INTERVAL_SECONDS = 1;

    public function __construct(
        #[Autowire(value: '%kernel.project_dir%/var/process_status.log')]
        private string $statusFile,
    ) {
    }

    public function __invoke(): void
    {
        // Heartbeat loop: stamp the status file every second, then hand back
        // to the supervisor so the worker is terminated through
        // ProcessTerminator - not by a self-called exit(), which would hang
        // in grpc's shutdown handler on grpc hosts.
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
