<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\ConfigCacheGuardConfig;
use PHPUnit\Framework\TestCase;

final class ConfigCacheGuardConfigTest extends TestCase
{
    /** @var string|false the process env value captured at setUp, restored at tearDown */
    private string|false $savedTrustEnv = false;

    protected function setUp(): void
    {
        ConfigCacheGuardConfig::reset();
        unset($_SERVER[ConfigCacheGuardConfig::ENV_VAR], $_ENV[ConfigCacheGuardConfig::ENV_VAR]);
        // The process env must not leak into tests either: a developer who
        // exported WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE while running the
        // suite would otherwise break strict-mode tests.
        $this->savedTrustEnv = function_exists('getenv') ? getenv(ConfigCacheGuardConfig::ENV_VAR) : false;
        if (function_exists('putenv')) {
            putenv(ConfigCacheGuardConfig::ENV_VAR);
        }
    }

    protected function tearDown(): void
    {
        ConfigCacheGuardConfig::reset();
        unset($_SERVER[ConfigCacheGuardConfig::ENV_VAR], $_ENV[ConfigCacheGuardConfig::ENV_VAR]);
        if (function_exists('putenv')) {
            if ($this->savedTrustEnv === false) {
                putenv(ConfigCacheGuardConfig::ENV_VAR);
            } else {
                putenv(ConfigCacheGuardConfig::ENV_VAR . '=' . $this->savedTrustEnv);
            }
        }
    }

    public function testGetReturnsNullInitially(): void
    {
        self::assertNull(ConfigCacheGuardConfig::get());
    }

    public function testSetStoresValue(): void
    {
        ConfigCacheGuardConfig::set(true);
        self::assertTrue(ConfigCacheGuardConfig::get());
    }

    public function testSetOverwritesPreviousValue(): void
    {
        ConfigCacheGuardConfig::set(true);
        ConfigCacheGuardConfig::set(false);
        self::assertFalse(ConfigCacheGuardConfig::get());
    }

    public function testResetClearsValue(): void
    {
        ConfigCacheGuardConfig::set(true);
        ConfigCacheGuardConfig::reset();
        self::assertNull(ConfigCacheGuardConfig::get());
    }

    public function testEnvVarNameIsExported(): void
    {
        self::assertSame('WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE', ConfigCacheGuardConfig::ENV_VAR);
    }

    public function testResolveIsFalseByDefault(): void
    {
        self::assertFalse(ConfigCacheGuardConfig::resolve());
    }

    public function testResolveReturnsSetValue(): void
    {
        ConfigCacheGuardConfig::set(true);
        self::assertTrue(ConfigCacheGuardConfig::resolve());
    }

    public function testResolvePrefersSetValueOverEnvironment(): void
    {
        $_SERVER[ConfigCacheGuardConfig::ENV_VAR] = '1';
        ConfigCacheGuardConfig::set(false);
        self::assertFalse(ConfigCacheGuardConfig::resolve());
    }

    public function testResolveReadsTruthyEnvValuesFromServer(): void
    {
        foreach (['1', 'true', 'on', 'yes', 'TRUE', ' Yes '] as $value) {
            $_SERVER[ConfigCacheGuardConfig::ENV_VAR] = $value;
            self::assertTrue(ConfigCacheGuardConfig::resolve(), "value '$value' should resolve to true");
            unset($_SERVER[ConfigCacheGuardConfig::ENV_VAR]);
        }
    }

    public function testResolveReadsTruthyEnvValueFromEnv(): void
    {
        $_ENV[ConfigCacheGuardConfig::ENV_VAR] = '1';
        self::assertTrue(ConfigCacheGuardConfig::resolve());
    }

    public function testResolveTreatsAbsentEmptyAndFalsyEnvValuesAsStrict(): void
    {
        foreach (['', '0', 'false', 'no', 'off', 'FALSE'] as $value) {
            $_SERVER[ConfigCacheGuardConfig::ENV_VAR] = $value;
            self::assertFalse(ConfigCacheGuardConfig::resolve(), "value '$value' should resolve to false");
            unset($_SERVER[ConfigCacheGuardConfig::ENV_VAR]);
        }
    }

    public function testResolveTreatsUnrecognisedEnvValuesAsStrict(): void
    {
        // Fail-closed: a typo must never silently unlock a security guard.
        foreach (['banana', 'ture', 'enabled', '1.0', 'yes please', 'ONN'] as $value) {
            $_SERVER[ConfigCacheGuardConfig::ENV_VAR] = $value;
            self::assertFalse(ConfigCacheGuardConfig::resolve(), "value '$value' should resolve to false");
            unset($_SERVER[ConfigCacheGuardConfig::ENV_VAR]);
        }
    }

    public function testResolveFallsBackToEnvWhenServerValueIsEmpty(): void
    {
        // An empty $_SERVER value must not shadow a truthy $_ENV value.
        $_SERVER[ConfigCacheGuardConfig::ENV_VAR] = '';
        $_ENV[ConfigCacheGuardConfig::ENV_VAR] = '1';
        self::assertTrue(ConfigCacheGuardConfig::resolve());
    }

    public function testResolveFallsBackToGetenvWhenSuperglobalsAreEmpty(): void
    {
        $env = getenv(ConfigCacheGuardConfig::ENV_VAR);
        putenv(ConfigCacheGuardConfig::ENV_VAR . '=1');
        try {
            self::assertTrue(ConfigCacheGuardConfig::resolve());
        } finally {
            if ($env === false) {
                putenv(ConfigCacheGuardConfig::ENV_VAR);
            } else {
                putenv(ConfigCacheGuardConfig::ENV_VAR . '=' . $env);
            }
        }
    }
}
