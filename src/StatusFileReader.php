<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

final readonly class StatusFileReader
{
    public function __construct(
        private ConfigLoader $configLoader,
    ) {
    }

    public function waitForFile(string $filePath, int $timeout): bool
    {
        $interval = 50_000;
        $elapsed = 0;
        $timeoutMicro = $timeout * 1_000_000;

        while (!file_exists($filePath) && $elapsed < $timeoutMicro) {
            usleep($interval);
            $elapsed += $interval;
        }

        return file_exists($filePath);
    }

    public function getStatusFilePath(): string
    {
        $config = $this->configLoader->getWorkermanConfig();
        $pidFile = $config['pid_file'] ?? '';

        if (!\is_string($pidFile)) {
            return '';
        }

        return preg_replace('/\.pid$/', '.status', $pidFile) ?? $pidFile;
    }

    public function getStatusTimeout(): int
    {
        $config = $this->configLoader->getWorkermanConfig();
        $timeout = $config['status_timeout'] ?? 5;

        return \is_int($timeout) ? $timeout : 5;
    }
}
