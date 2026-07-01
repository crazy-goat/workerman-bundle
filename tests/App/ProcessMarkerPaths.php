<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

/**
 * Shared marker file paths for process lifecycle E2E tests.
 *
 * Single source of truth for the marker files written by ProcessEventRecorder
 * and read by ProcessTest. Both the recorder (which runs inside the Workerman
 * daemon and resolves paths via %kernel.project_dir%) and the test (which
 * runs in PHPUnit and resolves paths via __DIR__) must agree on these paths.
 */
final class ProcessMarkerPaths
{
    public const START_MARKER = 'process_start.marker';
    public const ERROR_MARKER = 'process_error.marker';
    public const STATUS_FILE = 'process_status.log';
}
