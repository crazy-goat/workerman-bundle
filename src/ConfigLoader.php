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

        $previousUmask = umask(0077);
        try {
            $this->cache->write(sprintf('<?php return %s;', var_export($this->config, true)), $resources);
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

    /** @return array<string, mixed[]>|null */
    private function loadFromCache(): ?array
    {
        $cachePath = $this->cache->getPath();
        if (!is_file($cachePath)) {
            return null;
        }

        $this->validateCacheFilePermissions($cachePath);

        /** @var array<string, mixed[]> $cached */
        $cached = require $cachePath;

        return $this->config = $cached;
    }

    /**
     * Validate that the cached configuration file and its containing directory
     * have safe permissions.
     *
     * The config cache file is a PHP file that gets {@see require}d. An
     * attacker who can write to the cache directory can replace the file and
     * achieve arbitrary code execution at boot. On POSIX, replacing a file
     * requires write permission on the containing directory — not on the file
     * — so the directory is the primary object to check.
     *
     * The checks, in order:
     * 1. the containing directory must not be world-writable;
     * 2. the containing directory must not be group-writable by a group the
     *    process does not belong to (effective group plus supplementary
     *    groups are allowed);
     * 3. the cache file must be owned by the process's effective user
     *    (a file replaced by an attacker would be owned by the attacker);
     * 4. the cache file itself must not be world-writable (secondary signal).
     *
     * If the metadata cannot be read, a warning naming the path is emitted
     * (logged via the PSR-3 logger when one is available, otherwise raised as
     * an \E_USER_WARNING) and loading proceeds (fail-open with a signal) — the
     * check must not silently disappear on filesystems that do not report
     * permissions. The fail-open warning only applies when the cache file is
     * known to exist: `loadFromCache()` gates on `is_file($cachePath)` first,
     * so when that cannot be answered — e.g. the containing directory cannot
     * be statted (EACCES) — loading falls through to `loadFresh()` and the
     * caller gets a `LogicException('Configuration not available')`, not the
     * warning. The check is best-effort: it does not cover ACLs, extended
     * attributes, or filesystems that do not support POSIX permissions.
     *
     * @throws \RuntimeException if the cache directory or file is unsafe
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
        );

        if ($verdict['error'] !== null) {
            throw new \RuntimeException($verdict['error']);
        }

        if ($verdict['warn'] !== null) {
            if ($this->logger instanceof \Psr\Log\LoggerInterface) {
                $this->logger->warning($verdict['warn'], ['path' => $cachePath]);
            } else {
                trigger_error($verdict['warn'], \E_USER_WARNING);
            }
        }
    }

    /**
     * Pure permission decision for a cached configuration file.
     *
     * Concentrates every permission check in one place so that all branches
     * can be unit-tested without root privileges (no real chown/chgrp needed).
     *
     * @internal
     *
     * @param int|false $dirPerms fileperms() of the containing directory
     * @param int|false $dirGroup filegroup() of the containing directory
     * @param int|false $filePerms fileperms() of the cache file
     * @param int|false $fileOwner fileowner() of the cache file
     * @param int $euid effective user id of the loading process
     * @param int[] $processGroups every group the loading process belongs to
     *                             (effective group id plus supplementary groups)
     * @return array{warn: string|null, error: string|null} both null when the
     *         file is safe to load; `warn` set (and no `error`) when the
     *         metadata is unreadable and loading should proceed with a warning;
     *         `error` set (and no `warn`) when loading must be refused
     */
    public static function checkCacheFilePermissions(
        string $cachePath,
        int|false $dirPerms,
        int|false $dirGroup,
        int|false $filePerms,
        int|false $fileOwner,
        int $euid,
        array $processGroups,
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

        // 1. Replacing the cache file only needs write access to the directory.
        if (($dirPerms & 0002) !== 0) {
            return [
                'warn' => null,
                'error' => sprintf(
                    'The configuration cache directory "%s" is world-writable (%o). An attacker who can write '
                    . 'to the cache directory can replace the cache file and achieve arbitrary code execution at '
                    . 'boot. Ensure the cache directory is not writable by other users (e.g., chmod 0700 or 0750).',
                    $cacheDir,
                    $dirPerms & 0777,
                ),
            ];
        }

        // 2. A group-writable directory is only acceptable when the group is one of the process's own.
        if (($dirPerms & 0020) !== 0 && !\in_array($dirGroup, $processGroups, true)) {
            return [
                'warn' => null,
                'error' => sprintf(
                    'The configuration cache directory "%s" is writable by group %d (%o), which is not a group '
                    . 'the current process belongs to (process groups: %s). Another service in that group could '
                    . 'replace the cache file. Ensure the directory is not group-writable by other groups '
                    . '(e.g., chmod 0750 and chgrp to a group of the process).',
                    $cacheDir,
                    $dirGroup,
                    $dirPerms & 0777,
                    implode(', ', $processGroups),
                ),
            ];
        }

        // 3. Ownership: a file replaced by another user would be owned by that user.
        if ($fileOwner !== $euid) {
            return [
                'warn' => null,
                'error' => sprintf(
                    'The configuration cache file "%s" is owned by uid %d, not by the current process user '
                    . '(uid %d). The file may have been replaced by another user. Ensure the cache is written '
                    . 'by the same user that loads it (e.g., warm up with the runtime user, or chown the cache '
                    . 'to that user).',
                    $cachePath,
                    $fileOwner,
                    $euid,
                ),
            ];
        }

        // 4. World-writable file: secondary signal, kept from the original check.
        if (($filePerms & 0002) !== 0) {
            return [
                'warn' => null,
                'error' => sprintf(
                    'The configuration cache file "%s" is world-writable (%o). '
                    . 'This is a security risk: the cache directory must not be writable by untrusted users. '
                    . 'Ensure the cache directory has restrictive permissions (e.g., 0700 or 0750).',
                    $cachePath,
                    $filePerms & 0777,
                ),
            ];
        }

        return ['warn' => null, 'error' => null];
    }

    /** @return array<string, mixed[]> */
    private function loadFresh(): array
    {
        throw new \LogicException(
            'Configuration not available: no config has been set via setters and no cached '
            . 'config file exists. Ensure the cache has been warmed up before accessing config.',
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
