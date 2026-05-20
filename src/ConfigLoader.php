<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

class ConfigLoader implements CacheWarmerInterface
{
    /** @var mixed[] */
    private array $config;
    private readonly ConfigCache $cache;
    private readonly string $yamlConfigFilePath;

    public function __construct(string $projectDir, string $cacheDir, bool $isDebug)
    {
        $this->yamlConfigFilePath = sprintf('%s/config/packages/workerman.yaml', $projectDir);
        $cacheConfigFilePath = sprintf('%s/workerman/config.cache.php', $cacheDir);
        $this->cache = new ConfigCache($cacheConfigFilePath, $isDebug);
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $missingSections = [];
        foreach (ConfigSection::cases() as $section) {
            if (!isset($this->config[$section->value])) {
                $missingSections[] = $section->value;
            }
        }

        if ($missingSections !== []) {
            throw new \LogicException(
                sprintf(
                    'All config sections must be set before warming up. Missing: %s',
                    implode(', ', $missingSections),
                ),
            );
        }

        $resources = is_file($this->yamlConfigFilePath) ? [new FileResource($this->yamlConfigFilePath)] : [];
        $this->cache->write(sprintf('<?php return %s;', var_export($this->config, true)), $resources);

        return [];
    }

    public function isFresh(): bool
    {
        return $this->cache->isFresh();
    }

    /** @return array<string, mixed[]> */
    private function getConfig(): array
    {
        // If config was set directly via setters, return it without filesystem access.
        if (isset($this->config)) {
            return $this->config;
        }

        // Config not set in memory — try to load from cache file.
        // Use @ to suppress warning when cache file doesn't exist (e.g., during test teardown).
        $cachePath = $this->cache->getPath();
        if (is_file($cachePath)) {
            return $this->config = require $cachePath;
        }

        // Cache not available and config not injected — this happens when ServerManager
        // creates a fresh ConfigLoader outside the DI container. Return empty sections.
        return $this->config = [
            ConfigSection::WORKERMAN->value => [],
            ConfigSection::PROCESS->value => [],
            ConfigSection::SCHEDULER->value => [],
            ConfigSection::BUILD->value => [],
        ];
    }

    /** @param mixed[] $config */
    public function setWorkermanConfig(array $config): void
    {
        $this->config[ConfigSection::WORKERMAN->value] = $config;
    }

    /** @param mixed[] $config */
    public function setProcessConfig(array $config): void
    {
        $this->config[ConfigSection::PROCESS->value] = $config;
    }

    /** @param mixed[] $config */
    public function setSchedulerConfig(array $config): void
    {
        $this->config[ConfigSection::SCHEDULER->value] = $config;
    }

    /** @return mixed[] */
    public function getWorkermanConfig(): array
    {
        return $this->getConfig()[ConfigSection::WORKERMAN->value];
    }

    /** @return mixed[] */
    public function getProcessConfig(): array
    {
        return $this->getConfig()[ConfigSection::PROCESS->value];
    }

    /** @param mixed[] $config */
    public function setBuildConfig(array $config): void
    {
        $this->config[ConfigSection::BUILD->value] = $config;
    }

    /** @return mixed[] */
    public function getSchedulerConfig(): array
    {
        return $this->getConfig()[ConfigSection::SCHEDULER->value];
    }

    /** @return mixed[] */
    public function getBuildConfig(): array
    {
        return $this->getConfig()[ConfigSection::BUILD->value];
    }
}
