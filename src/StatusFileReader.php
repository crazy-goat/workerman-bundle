<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use CrazyGoat\WorkermanBundle\Util\Wait;

final readonly class StatusFileReader
{
    public function __construct(
        private ConfigLoader $configLoader,
    ) {
    }

    public function waitForFile(string $filePath, int $timeout): bool
    {
        return Wait::until(static fn(): bool => file_exists($filePath), $timeout);
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
