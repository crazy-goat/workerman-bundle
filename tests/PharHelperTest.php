<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\PharHelper;
use PHPUnit\Framework\TestCase;

final class PharHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['WORKERMAN_RUNTIME_DIR']);
    }

    public function testIsPharReturnsFalseInNormalMode(): void
    {
        self::assertFalse(PharHelper::isPhar());
    }

    public function testGetRuntimeDirReturnsProjectDirInNormalMode(): void
    {
        self::assertSame('/app', PharHelper::getRuntimeDir('/app'));
    }

    public function testGetRuntimeDirTrimsTrailingSlash(): void
    {
        self::assertSame('/app', PharHelper::getRuntimeDir('/app/'));
    }

    public function testGetRuntimeDirRespectsEnvVar(): void
    {
        $_SERVER['WORKERMAN_RUNTIME_DIR'] = '/custom/runtime';

        self::assertSame('/custom/runtime', PharHelper::getRuntimeDir('/app'));
    }

    public function testGetRuntimeDirTrimsTrailingSlashFromEnvVar(): void
    {
        $_SERVER['WORKERMAN_RUNTIME_DIR'] = '/custom/runtime/';

        self::assertSame('/custom/runtime', PharHelper::getRuntimeDir('/app'));
    }

    public function testResolveRuntimePathReturnsPathUnchangedInNormalMode(): void
    {
        self::assertSame(
            '/app/var/cache/test',
            PharHelper::resolveRuntimePath('/app/var/cache/test', '/app'),
        );
    }

    public function testResolveRuntimePathReplacesProjectDirPrefixWhenRuntimeDirDiffers(): void
    {
        $_SERVER['WORKERMAN_RUNTIME_DIR'] = '/runtime';

        self::assertSame(
            '/runtime/var/cache/test',
            PharHelper::resolveRuntimePath('/app/var/cache/test', '/app'),
        );
    }

    public function testResolveRuntimePathReturnsPathUnchangedWhenNotUnderProjectDir(): void
    {
        $_SERVER['WORKERMAN_RUNTIME_DIR'] = '/runtime';

        self::assertSame(
            '/other/path/file.txt',
            PharHelper::resolveRuntimePath('/other/path/file.txt', '/app'),
        );
    }

    public function testResolveRuntimePathTrimsTrailingSlashFromProjectDir(): void
    {
        self::assertSame(
            '/app/var/cache/test',
            PharHelper::resolveRuntimePath('/app/var/cache/test', '/app/'),
        );
    }
}
