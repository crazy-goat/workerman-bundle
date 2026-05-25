<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Phar;

use CrazyGoat\WorkermanBundle\Phar\ByteFormatter;
use PHPUnit\Framework\TestCase;

final class ByteFormatterTest extends TestCase
{
    public function testZeroBytes(): void
    {
        self::assertSame('0 B', ByteFormatter::format(0));
    }

    public function testSingleByte(): void
    {
        self::assertSame('1 B', ByteFormatter::format(1));
    }

    public function testUpperBoundOfBytes(): void
    {
        self::assertSame('1023 B', ByteFormatter::format(1023));
    }

    public function testLowerBoundOfKilobytes(): void
    {
        self::assertSame('1 KB', ByteFormatter::format(1024));
    }

    public function testFractionalKilobytes(): void
    {
        self::assertSame('1.5 KB', ByteFormatter::format(1536));
    }

    public function testRoundedKilobytes(): void
    {
        self::assertSame('1.23 KB', ByteFormatter::format(1258));
    }

    public function testJustBelowMegabyteBoundaryRoundsUpInKilobytes(): void
    {
        self::assertSame('1024 KB', ByteFormatter::format(1048575));
    }

    public function testLowerBoundOfMegabytes(): void
    {
        self::assertSame('1 MB', ByteFormatter::format(1048576));
    }

    public function testFractionalMegabytes(): void
    {
        self::assertSame('2.5 MB', ByteFormatter::format(2621440));
    }

    public function testLowerBoundOfGigabytes(): void
    {
        self::assertSame('1 GB', ByteFormatter::format(1073741824));
    }

    public function testFractionalGigabytes(): void
    {
        self::assertSame('1.5 GB', ByteFormatter::format(1610612736));
    }

    public function testBeyondGigabytesStaysInGigabytes(): void
    {
        self::assertSame('1024 GB', ByteFormatter::format(1099511627776));
    }
}
