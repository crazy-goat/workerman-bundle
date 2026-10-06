<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Util;

use CrazyGoat\WorkermanBundle\Util\ProcStatParser;
use PHPUnit\Framework\TestCase;

final class ProcStatParserTest extends TestCase
{
    public function testSplitFieldsParsesStatePpidAndStartTime(): void
    {
        $fields = ProcStatParser::splitFields('1234 (php) R 567 1234 0 0 0 0 0 0 0 0 0 0 0 0 0 1 0 0 12345 0 0');

        $this->assertNotNull($fields);
        $this->assertSame('R', $fields[0]);
        $this->assertSame('567', $fields[1]);
        $this->assertSame('12345', $fields[19]);
    }

    public function testSplitFieldsHandlesCommWithParensAndSpaces(): void
    {
        $fields = ProcStatParser::splitFields('1234 (my (weird) app) S 1 1234 0 0 0 0 0 0 0 0 0 0 0 0 0 1 0 0 6789 0 0');

        $this->assertNotNull($fields);
        $this->assertSame('S', $fields[0]);
        $this->assertSame('1', $fields[1]);
        $this->assertSame('6789', $fields[19]);
    }

    public function testSplitFieldsReturnsNullWithoutClosingParen(): void
    {
        $this->assertNull(ProcStatParser::splitFields('1234 php R 1'));
    }
}
