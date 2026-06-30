<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\CacheWarmupTimeoutConfig;
use PHPUnit\Framework\TestCase;

final class CacheWarmupTimeoutConfigTest extends TestCase
{
    protected function setUp(): void
    {
        CacheWarmupTimeoutConfig::reset();
    }

    protected function tearDown(): void
    {
        CacheWarmupTimeoutConfig::reset();
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
}
