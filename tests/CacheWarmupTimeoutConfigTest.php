<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\CacheWarmupTimeoutConfig;
use PHPUnit\Framework\TestCase;

final class CacheWarmupTimeoutConfigTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedServer = [];

    private mixed $savedGetenv = null;

    protected function setUp(): void
    {
        CacheWarmupTimeoutConfig::reset();

        $this->savedServer = [
            'SERVER' => $_SERVER[CacheWarmupTimeoutConfig::ENV_VAR] ?? null,
            'ENV' => $_ENV[CacheWarmupTimeoutConfig::ENV_VAR] ?? null,
        ];
        unset($_SERVER[CacheWarmupTimeoutConfig::ENV_VAR], $_ENV[CacheWarmupTimeoutConfig::ENV_VAR]);
        $this->savedGetenv = getenv(CacheWarmupTimeoutConfig::ENV_VAR);
        putenv(CacheWarmupTimeoutConfig::ENV_VAR);
    }

    protected function tearDown(): void
    {
        CacheWarmupTimeoutConfig::reset();

        if ($this->savedServer['SERVER'] !== null) {
            $_SERVER[CacheWarmupTimeoutConfig::ENV_VAR] = $this->savedServer['SERVER'];
        } else {
            unset($_SERVER[CacheWarmupTimeoutConfig::ENV_VAR]);
        }
        if ($this->savedServer['ENV'] !== null) {
            $_ENV[CacheWarmupTimeoutConfig::ENV_VAR] = $this->savedServer['ENV'];
        } else {
            unset($_ENV[CacheWarmupTimeoutConfig::ENV_VAR]);
        }
        if (is_string($this->savedGetenv)) {
            putenv(CacheWarmupTimeoutConfig::ENV_VAR . '=' . $this->savedGetenv);
        } else {
            putenv(CacheWarmupTimeoutConfig::ENV_VAR);
        }
    }

    public function testGetReturnsNullInitially(): void
    {
        self::assertNull(CacheWarmupTimeoutConfig::get());
    }

    public function testSetStoresValue(): void
    {
        CacheWarmupTimeoutConfig::set(42);
        self::assertSame(42, CacheWarmupTimeoutConfig::get());
    }

    public function testSetOverwritesPreviousValue(): void
    {
        CacheWarmupTimeoutConfig::set(10);
        CacheWarmupTimeoutConfig::set(99);
        self::assertSame(99, CacheWarmupTimeoutConfig::get());
    }

    public function testResetClearsValue(): void
    {
        CacheWarmupTimeoutConfig::set(50);
        CacheWarmupTimeoutConfig::reset();
        self::assertNull(CacheWarmupTimeoutConfig::get());
    }

    public function testSetRejectsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WORKERMAN_CACHE_WARMUP_TIMEOUT must be a positive integer');
        CacheWarmupTimeoutConfig::set(0);
    }

    public function testSetRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WORKERMAN_CACHE_WARMUP_TIMEOUT must be a positive integer');
        CacheWarmupTimeoutConfig::set(-1);
    }

    public function testDefaultIs30(): void
    {
        self::assertSame(30, CacheWarmupTimeoutConfig::DEFAULT);
    }

    public function testEnvVarNameIsExported(): void
    {
        self::assertSame('WORKERMAN_CACHE_WARMUP_TIMEOUT', CacheWarmupTimeoutConfig::ENV_VAR);
    }

    public function testSetRejectionLeavesPreviousValueIntact(): void
    {
        CacheWarmupTimeoutConfig::set(42);

        try {
            CacheWarmupTimeoutConfig::set(0);
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertSame(42, CacheWarmupTimeoutConfig::get());
        }
    }

    public function testResolveReturnsHolderValueWhenSet(): void
    {
        CacheWarmupTimeoutConfig::set(55);
        self::assertSame(55, CacheWarmupTimeoutConfig::resolve());
    }

    public function testResolveReturnsDefaultWhenHolderEmpty(): void
    {
        self::assertSame(CacheWarmupTimeoutConfig::DEFAULT, CacheWarmupTimeoutConfig::resolve());
    }

    /**
     * Issue #759: on the Runner path Runtime::getRunner() runs pre-kernel-boot,
     * so no set() has run yet — resolve() must read the env var lazily.
     */
    public function testResolveReadsEnvVarFromServerWhenHolderEmpty(): void
    {
        $_SERVER[CacheWarmupTimeoutConfig::ENV_VAR] = '77';

        self::assertSame(77, CacheWarmupTimeoutConfig::resolve());
    }

    public function testResolveReadsEnvVarFromEnvSuperglobal(): void
    {
        $_ENV[CacheWarmupTimeoutConfig::ENV_VAR] = '55';

        self::assertSame(55, CacheWarmupTimeoutConfig::resolve());
    }

    public function testResolveReadsEnvVarFromGetenvFallback(): void
    {
        putenv(CacheWarmupTimeoutConfig::ENV_VAR . '=63');

        self::assertSame(63, CacheWarmupTimeoutConfig::resolve());
    }

    public function testResolvePrefersServerOverEnvAndGetenv(): void
    {
        $_SERVER[CacheWarmupTimeoutConfig::ENV_VAR] = '11';
        $_ENV[CacheWarmupTimeoutConfig::ENV_VAR] = '22';
        putenv(CacheWarmupTimeoutConfig::ENV_VAR . '=33');

        self::assertSame(11, CacheWarmupTimeoutConfig::resolve());
    }

    public function testResolvePrefersEnvSuperglobalOverGetenv(): void
    {
        $_ENV[CacheWarmupTimeoutConfig::ENV_VAR] = '22';
        putenv(CacheWarmupTimeoutConfig::ENV_VAR . '=33');

        self::assertSame(22, CacheWarmupTimeoutConfig::resolve());
    }

    public function testExplicitSetWinsOverEnvVar(): void
    {
        $_SERVER[CacheWarmupTimeoutConfig::ENV_VAR] = '77';
        CacheWarmupTimeoutConfig::set(45);

        self::assertSame(45, CacheWarmupTimeoutConfig::resolve());
    }

    public function testResolveIgnoresEmptyEnvVar(): void
    {
        $_SERVER[CacheWarmupTimeoutConfig::ENV_VAR] = '';

        self::assertSame(CacheWarmupTimeoutConfig::DEFAULT, CacheWarmupTimeoutConfig::resolve());
    }

    public function testResolveTreatsWhitespaceOnlyEnvVarAsAbsent(): void
    {
        $_SERVER[CacheWarmupTimeoutConfig::ENV_VAR] = '   ';

        self::assertSame(CacheWarmupTimeoutConfig::DEFAULT, CacheWarmupTimeoutConfig::resolve());
    }

    public function testResolveFallsThroughBlankServerValueToEnvSuperglobal(): void
    {
        $_SERVER[CacheWarmupTimeoutConfig::ENV_VAR] = '  ';
        $_ENV[CacheWarmupTimeoutConfig::ENV_VAR] = '55';

        self::assertSame(55, CacheWarmupTimeoutConfig::resolve());
    }

    public function testResolveTrimsPaddedEnvVar(): void
    {
        $_SERVER[CacheWarmupTimeoutConfig::ENV_VAR] = ' 77 ';

        self::assertSame(77, CacheWarmupTimeoutConfig::resolve());
    }

    /**
     * @dataProvider invalidEnvValueProvider
     */
    public function testResolveRejectsNonPositiveEnvVar(string $raw): void
    {
        $_SERVER[CacheWarmupTimeoutConfig::ENV_VAR] = $raw;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WORKERMAN_CACHE_WARMUP_TIMEOUT must be a positive integer');
        CacheWarmupTimeoutConfig::resolve();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEnvValueProvider(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'non-numeric casts to zero' => ['abc'];
    }
}
