<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Protocol\Http\Response\StreamedBinaryFileResponse;
use PHPUnit\Framework\TestCase;

final class StreamedBinaryFileResponseTest extends TestCase
{
    public function testCanBeInstantiated(): void
    {
        $testFile = sys_get_temp_dir() . '/test_streamed_' . uniqid() . '.txt';
        file_put_contents($testFile, 'test content');

        $response = new StreamedBinaryFileResponse($testFile);

        $this->assertInstanceOf(StreamedBinaryFileResponse::class, $response);

        unlink($testFile);
    }

    public function testExtendsBinaryFileResponse(): void
    {
        $testFile = sys_get_temp_dir() . '/test_streamed_' . uniqid() . '.txt';
        file_put_contents($testFile, 'test content');

        $response = new StreamedBinaryFileResponse($testFile);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response);

        unlink($testFile);
    }
}
