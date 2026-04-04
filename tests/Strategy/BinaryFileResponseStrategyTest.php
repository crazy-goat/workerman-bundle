<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\Strategy\BinaryFileResponseStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Response;

final class BinaryFileResponseStrategyTest extends TestCase
{
    private string $testFile;

    protected function setUp(): void
    {
        $this->testFile = sys_get_temp_dir() . '/test_binary_file_' . uniqid() . '.txt';
        file_put_contents($this->testFile, 'Hello World from binary file!');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testSupportsReturnsTrueForBinaryFileResponse(): void
    {
        $strategy = new BinaryFileResponseStrategy();
        $binaryResponse = new BinaryFileResponse($this->testFile);

        $this->assertTrue($strategy->supports($binaryResponse));
    }

    public function testSupportsReturnsFalseForRegularResponse(): void
    {
        $strategy = new BinaryFileResponseStrategy();
        $regularResponse = new Response('Hello');

        $this->assertFalse($strategy->supports($regularResponse));
    }

    public function testConvertReturnsWorkermanResponseWithFile(): void
    {
        $strategy = new BinaryFileResponseStrategy();
        $binaryResponse = new BinaryFileResponse($this->testFile, \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
        ]);

        $workermanResponse = $strategy->convert($binaryResponse, [
            'Content-Type' => ['text/plain'],
        ]);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        // Workerman Response with file has file property set
        $this->assertNotNull($workermanResponse->file);
    }

    public function testConvertHandlesFileWithCustomHeaders(): void
    {
        $strategy = new BinaryFileResponseStrategy();
        $binaryResponse = new BinaryFileResponse($this->testFile, \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="report.pdf"',
        ]);

        $workermanResponse = $strategy->convert($binaryResponse, [
            'Content-Type' => ['application/pdf'],
            'Content-Disposition' => ['attachment; filename="report.pdf"'],
        ]);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertNotNull($workermanResponse->file);
    }

    public function testConvertHandlesNotFoundResponse(): void
    {
        $strategy = new BinaryFileResponseStrategy();
        $binaryResponse = new BinaryFileResponse($this->testFile, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);

        $workermanResponse = $strategy->convert($binaryResponse, []);

        $this->assertSame(404, $workermanResponse->getStatusCode());
    }

    public function testConvertThrowsForNonBinaryFileResponse(): void
    {
        $strategy = new BinaryFileResponseStrategy();
        $regularResponse = new Response('Hello');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected BinaryFileResponse');

        $strategy->convert($regularResponse, []);
    }

    public function testConvertHandlesTempFileObject(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        // Create a temp file object
        $tempFile = new \SplTempFileObject();
        $tempFile->fwrite('Temp file content');

        // Create BinaryFileResponse with temp file
        $binaryResponse = new BinaryFileResponse($this->testFile);

        // Use reflection to set the temp file object
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('tempFileObject');
        $property->setValue($binaryResponse, $tempFile);

        $workermanResponse = $strategy->convert($binaryResponse, []);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        // For temp files, content is read into body
        $this->assertSame('Temp file content', $workermanResponse->rawBody());
    }

    public function testConvertHandlesRangeRequest(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        // Create response with offset and maxlen (simulating range request)
        $binaryResponse = new BinaryFileResponse($this->testFile, \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'Content-Range' => 'bytes 0-4/29',
        ]);

        // Use reflection to set offset and maxlen
        $reflection = new \ReflectionClass($binaryResponse);

        $offsetProperty = $reflection->getProperty('offset');
        $offsetProperty->setValue($binaryResponse, 0);

        $maxlenProperty = $reflection->getProperty('maxlen');
        $maxlenProperty->setValue($binaryResponse, 5);

        $workermanResponse = $strategy->convert($binaryResponse, [
            'Content-Range' => ['bytes 0-4/29'],
        ]);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertNotNull($workermanResponse->file);
    }
}
