<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use Psr\Log\LoggerInterface;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

final class ConfigLoader implements CacheWarmerInterface
{
    /** @var array<string, mixed[]> */
    private array $config;
    private readonly ConfigCache $cache;
    private readonly string $yamlConfigFilePath;

    /**
     * @var list<string>
     */
    private array $envVarNames = [];

    /**
     * @var array<string, string|null>|null
     */
    private ?array $trackedEnv = null;

    public function __construct(
        string $projectDir,
        string $cacheDir,
        bool $isDebug,
        private readonly ?LoggerInterface $logger = null,
    ) {
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

        $payload = $this->config;
        if ($this->envVarNames !== []) {
            $payload = [
                'config' => $this->config,
                'env' => $this->readEnvValues($this->envVarNames),
            ];
        }

        $previousUmask = umask(0077);
        try {
            $this->cache->write(sprintf('<?php return %s;', var_export($payload, true)), $resources);
        } finally {
            umask($previousUmask);
        }

        return [];
    }

    public function isFresh(): bool
    {
        return $this->cache->isFresh();
    }

    /** @return array<string, mixed[]> */
    private function getConfig(): array
    {
        return $this->loadFromMemory()
            ?? $this->loadFromCache()
            ?? $this->loadFresh();
    }

    /** @return array<string, mixed[]>|null */
    private function loadFromMemory(): ?array
    {
        return $this->config ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCachePayload(): ?array
    {
        $cachePath = $this->cache->getPath();
        if (!is_file($cachePath)) {
            return null;
        }

        $this->validateCacheFilePermissions($cachePath);

        /** @var array<string, mixed> $cached */
        $cached = require $cachePath;

        return $cached;
    }

    /** @return array<string, mixed[]>|null */
    private function loadFromCache(): ?array
    {
        $cached = $this->readCachePayload();
        if ($cached === null) {
            return null;
        }

        if (isset($cached['config'], $cached['env']) && \is_array($cached['config']) && \is_array($cached['env'])) {
            /** @var array<string, mixed[]> $config */
            $config = $cached['config'];
            /** @var array<string, string|null> $env */
            $env = $cached['env'];
            $this->trackedEnv = $env;

            return $this->config = $config;
        }

        /** @var array<string, mixed[]> $cached */
        return $this->config = $cached;
    }

    public function hasTrackedEnvChanged(): bool
    {
        if ($this->trackedEnv === null) {
            $this->loadTrackedEnvFromCache();
        }

        if ($this->trackedEnv === null || $this->trackedEnv === []) {
            return false;
        }

        return $this->readEnvValues(array_keys($this->trackedEnv)) !== $this->trackedEnv;
    }

    private function loadTrackedEnvFromCache(): void
    {
        $cached = $this->readCachePayload();
        if ($cached === null) {
            return;
        }

        if (isset($cached['config'], $cached['env']) && \is_array($cached['env'])) {
            /** @var array<string, string|null> $env */
            $env = $cached['env'];
            $this->trackedEnv = $env;
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function validateCacheFilePermissions(string $cachePath): void
    {
        $cacheDir = \dirname($cachePath);
        $groups = posix_getgroups();

        $verdict = self::checkCacheFilePermissions(
            $cachePath,
            @fileperms($cacheDir),
            @filegroup($cacheDir),
            @fileperms($cachePath),
            @fileowner($cachePath),
            posix_geteuid(),
            [posix_getegid(), ...($groups === false ? [] : $groups)],
            ConfigCacheGuardConfig::resolve(),
        );

        if ($verdict['error'] !== null) {
            throw new \RuntimeException($verdict['error']);
        }

        if ($verdict['warn'] !== null) {
            if ($this->logger instanceof \Psr\Log\LoggerInterface) {
                $this->logger->warning($verdict['warn'], ['path' => $cachePath]);
            } else {
                error_log($verdict['warn']);
            }
        }
    }

    /**
     * @internal
     * @param int[] $processGroups
     * @return array{warn: string|null, error: string|null}
     */
    public static function checkCacheFilePermissions(
        string $cachePath,
        int|false $dirPerms,
        int|false $dirGroup,
        int|false $filePerms,
        int|false $fileOwner,
        int $euid,
        array $processGroups,
        bool $trustCacheDir = false,
    ): array {
        if ($dirPerms === false || $dirGroup === false || $filePerms === false || $fileOwner === false) {
            return [
                'warn' => sprintf(
                    'Cannot verify permissions of the configuration cache file "%s"; loading it without a permission check',
                    $cachePath,
                ),
                'error' => null,
            ];
        }

        $cacheDir = \dirname($cachePath);

        if (($dirPerms & 0002) !== 0) {
            return self::verdict(
                $trustCacheDir,
                sprintf(
                    'The configuration cache directory "%s" is world-writable (%o). An attacker who can write '
                    . 'to the cache directory can replace the cache file and achieve arbitrary code execution at '
                    . 'boot. Ensure the cache directory is not writable by other users (e.g., chmod 0700 or 0750).',
                    $cacheDir,
                    $dirPerms & 0777,
                ),
            );
        }

        if (($dirPerms & 0020) !== 0 && !\in_array($dirGroup, $processGroups, true)) {
            return self::verdict(
                $trustCacheDir,
                sprintf(
                    'The configuration cache directory "%s" is writable by group %d (%o), which is not a group '
                    . 'the current process belongs to (process groups: %s). Another service in that group could '
                    . 'replace the cache file. Ensure the directory is not group-writable by other groups '
                    . '(e.g., chmod 0750 and chgrp to a group of the process).',
                    $cacheDir,
                    $dirGroup,
                    $dirPerms & 0777,
                    implode(', ', $processGroups),
                ),
            );
        }

        if ($fileOwner !== $euid) {
            return self::verdict(
                $trustCacheDir,
                sprintf(
                    'The configuration cache file "%s" is owned by uid %d, not by the current process user '
                    . '(uid %d). The file may have been replaced by another user. Ensure the cache is written '
                    . 'by the same user that loads it (e.g., warm up with the runtime user, or chown the cache '
                    . 'to that user).',
                    $cachePath,
                    $fileOwner,
                    $euid,
                ),
            );
        }

        if (($filePerms & 0002) !== 0) {
            return self::verdict(
                $trustCacheDir,
                sprintf(
                    'The configuration cache file "%s" is world-writable (%o). '
                    . 'This is a security risk: the cache directory must not be writable by untrusted users. '
                    . 'Ensure the cache directory has restrictive permissions (e.g., 0700 or 0750).',
                    $cachePath,
                    $filePerms & 0777,
                ),
            );
        }

        return ['warn' => null, 'error' => null];
    }

    /**
     * @return array{warn: string|null, error: string|null}
     */
    private static function verdict(bool $trustCacheDir, string $message): array
    {
        if ($trustCacheDir) {
            return [
                'warn' => sprintf(
                    'The config-cache permission guard is explicitly downgraded (%s is set) and the cache '
                    . 'directory is trusted as-is by the deployment; loading proceeds despite: %s',
                    ConfigCacheGuardConfig::ENV_VAR,
                    $message,
                ),
                'error' => null,
            ];
        }

        return ['warn' => null, 'error' => $message];
    }

    /** @return array<string, mixed[]> */
    private function loadFresh(): array
    {
        throw new \LogicException(
            'Configuration not available: no config has been set via setters and no cached '
            . 'config file could be loaded. Ensure the cache has been warmed up before accessing config.',
        );
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

    /** @param mixed[] $config */
    public function setBuildConfig(array $config): void
    {
        $this->config[ConfigSection::BUILD->value] = $config;
    }

    /**
     * @param mixed[] $names
     */
    public function setWorkermanEnvVarNames(array $names): void
    {
        $short = [];
        foreach ($names as $name) {
            if (!\is_string($name) || $name === '') {
                continue;
            }

            foreach (self::envNameCandidates($name) as $candidate) {
                $short[$candidate] = true;
            }
        }

        $this->envVarNames = array_keys($short);
        sort($this->envVarNames);
    }

    /**
     * @var array<string, true>
     */
    private const ENV_PROCESSORS = [
        'base64' => true,
        'bool' => true,
        'not' => true,
        'const' => true,
        'csv' => true,
        'file' => true,
        'float' => true,
        'int' => true,
        'json' => true,
        'key' => true,
        'url' => true,
        'query_string' => true,
        'resolve' => true,
        'default' => true,
        'string' => true,
        'trim' => true,
        'require' => true,
        'enum' => true,
        'shuffle' => true,
        'defined' => true,
        'urlencode' => true,
    ];

    public static function shortEnvName(string $name): string
    {
        return self::envNameCandidates($name)[0] ?? $name;
    }

    /**
     * @return list<string>
     */
    private static function envNameCandidates(string $name): array
    {
        if (!str_contains($name, ':')) {
            return $name === '' ? [] : [$name];
        }

        $parts = explode(':', $name);
        while ($parts !== [] && isset(self::ENV_PROCESSORS[strtolower($parts[0])])) {
            array_shift($parts);
        }

        $candidates = [];
        foreach ($parts as $part) {
            if ($part !== '' && !isset($candidates[$part])) {
                $candidates[$part] = true;
            }
        }

        return array_keys($candidates);
    }

    /**
     * @param list<string> $names
     * @return array<string, string|null>
     */
    private function readEnvValues(array $names): array
    {
        $values = [];
        foreach ($names as $name) {
            $raw = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
            $values[$name] = $raw === false ? null : (string) $raw;
        }

        return $values;
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
