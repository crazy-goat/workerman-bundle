<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

final readonly class StatusFileReader
{
    public function __construct(
        private ConfigLoader $configLoader,
        private WaitStrategy $waitStrategy = new WaitStrategy(),
    ) {
    }

    public function waitForFile(string $filePath, int $timeout): bool
    {
        return $this->waitStrategy->waitFor(
            static fn(): bool => file_exists($filePath),
            $timeout,
        );
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
