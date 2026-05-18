<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Protocol\Http\Response\StreamedBinaryFileResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class StreamedBinaryFileResponseTest extends TestCase
{
    private string $testFile;

    protected function setUp(): void
    {
        $this->testFile = sys_get_temp_dir() . '/test_streamed_binary_' . uniqid() . '.txt';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testStreamContentReturnsGenerator(): void
    {
        file_put_contents($this->testFile, 'test content');
        $response = new StreamedBinaryFileResponse($this->testFile);

        $generator = $response->streamContent();

        $this->assertInstanceOf(\Generator::class, $generator);
    }

    public function testStreamContentReturnsEarlyForUnsuccessfulResponse(): void
    {
        file_put_contents($this->testFile, 'test content');
        $response = new StreamedBinaryFileResponse($this->testFile, Response::HTTP_NOT_FOUND);

        $generator = $response->streamContent();

        $this->assertInstanceOf(\Generator::class, $generator);
        $this->assertSame($response, $generator->getReturn());
    }

    public function testStreamContentReturnsEarlyWhenMaxlenIsZero(): void
    {
        file_put_contents($this->testFile, 'test content');
        $response = new StreamedBinaryFileResponse($this->testFile);

        $reflection = new \ReflectionClass($response);
        $maxlenProperty = $reflection->getProperty('maxlen');
        $maxlenProperty->setValue($response, 0);

        $generator = $response->streamContent();

        $this->assertInstanceOf(\Generator::class, $generator);
        $this->assertSame($response, $generator->getReturn());
    }

    public function testStreamContentYieldsFullFileContent(): void
    {
        $content = 'Hello World from streamed binary file!';
        file_put_contents($this->testFile, $content);
        $response = new StreamedBinaryFileResponse($this->testFile);

        $generator = $response->streamContent();
        $output = '';

        foreach ($generator as $chunk) {
            $output .= $chunk;
        }

        $this->assertSame($content, $output);
    }

    public function testStreamContentRespectsOffset(): void
    {
        $content = 'Hello World from streamed binary file!';
        file_put_contents($this->testFile, $content);
        $response = new StreamedBinaryFileResponse($this->testFile);

        $reflection = new \ReflectionClass($response);
        $offsetProperty = $reflection->getProperty('offset');
        $offsetProperty->setValue($response, 6);

        $generator = $response->streamContent();
        $output = '';

        foreach ($generator as $chunk) {
            $output .= $chunk;
        }

        $this->assertSame('World from streamed binary file!', $output);
    }

    public function testStreamContentRespectsMaxlen(): void
    {
        $content = 'Hello World from streamed binary file!';
        file_put_contents($this->testFile, $content);
        $response = new StreamedBinaryFileResponse($this->testFile);

        $reflection = new \ReflectionClass($response);
        $maxlenProperty = $reflection->getProperty('maxlen');
        $maxlenProperty->setValue($response, 13);

        $generator = $response->streamContent();
        $output = '';

        foreach ($generator as $chunk) {
            $output .= $chunk;
        }

        $this->assertSame('Hello World f', $output);
    }

    public function testStreamContentHandlesLargeFile(): void
    {
        $content = str_repeat('A', 100000);
        file_put_contents($this->testFile, $content);
        $response = new StreamedBinaryFileResponse($this->testFile);

        $generator = $response->streamContent();
        $output = '';

        foreach ($generator as $chunk) {
            $output .= $chunk;
        }

        $this->assertSame(100000, strlen($output));
        $this->assertSame($content, $output);
    }

    public function testStreamContentWithSplTempFileObject(): void
    {
        $content = 'Temp file object content for streaming';
        $tempFile = new \SplTempFileObject();
        $tempFile->fwrite($content);

        $dummyFile = sys_get_temp_dir() . '/dummy_' . uniqid() . '.txt';
        file_put_contents($dummyFile, 'dummy');
        $response = new StreamedBinaryFileResponse($dummyFile);

        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('tempFileObject');
        $property->setValue($response, $tempFile);
        unlink($dummyFile);

        $generator = $response->streamContent();
        $output = '';

        foreach ($generator as $chunk) {
            $output .= $chunk;
        }

        $this->assertSame($content, $output);
    }

    public function testStreamContentDoesNotDeleteSplTempFileObjectInFinally(): void
    {
        $tempFile = new \SplTempFileObject();
        $tempFile->fwrite('content');

        $dummyFile = sys_get_temp_dir() . '/dummy_' . uniqid() . '.txt';
        file_put_contents($dummyFile, 'dummy');
        $response = new StreamedBinaryFileResponse($dummyFile);

        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('tempFileObject');
        $property->setValue($response, $tempFile);
        $deleteProperty = $reflection->getProperty('deleteFileAfterSend');
        $deleteProperty->setValue($response, true);
        unlink($dummyFile);

        $generator = $response->streamContent();

        foreach ($generator as $chunk) {
            // consume generator
        }

        $tempFile->rewind();
        $this->assertSame('content', $tempFile->fread(1024));
    }

    public function testStreamContentDeletesFileWhenDeleteFileAfterSendIsTrue(): void
    {
        file_put_contents($this->testFile, 'delete me after stream');
        $response = new StreamedBinaryFileResponse($this->testFile);

        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($response, true);

        $this->assertFileExists($this->testFile);

        $generator = $response->streamContent();

        foreach ($generator as $chunk) {
            // consume generator
        }

        $this->assertFileDoesNotExist($this->testFile);
    }

    public function testStreamContentDoesNotDeleteFileWhenDeleteFileAfterSendIsFalse(): void
    {
        file_put_contents($this->testFile, 'do not delete me');
        $response = new StreamedBinaryFileResponse($this->testFile);

        $this->assertFileExists($this->testFile);

        $generator = $response->streamContent();

        foreach ($generator as $chunk) {
            // consume generator
        }

        $this->assertFileExists($this->testFile);
    }

    public function testStreamContentReturnsResponseAsReturnValue(): void
    {
        file_put_contents($this->testFile, 'test');
        $response = new StreamedBinaryFileResponse($this->testFile);

        $generator = $response->streamContent();

        foreach ($generator as $chunk) {
            // consume generator
        }

        $this->assertSame($response, $generator->getReturn());
    }

    public function testStreamContentYieldsEmptyForEmptyFile(): void
    {
        file_put_contents($this->testFile, '');
        $response = new StreamedBinaryFileResponse($this->testFile);

        $generator = $response->streamContent();
        $output = '';

        foreach ($generator as $chunk) {
            $output .= $chunk;
        }

        $this->assertSame('', $output);
    }

    public function testStreamContentWorksWithChunkSize(): void
    {
        $content = 'AB';
        file_put_contents($this->testFile, $content);
        $response = new StreamedBinaryFileResponse($this->testFile);

        $reflection = new \ReflectionClass($response);
        $chunkSizeProperty = $reflection->getProperty('chunkSize');
        $chunkSizeProperty->setValue($response, 1);

        $generator = $response->streamContent();
        $chunks = [];

        foreach ($generator as $chunk) {
            $chunks[] = $chunk;
        }

        $this->assertCount(2, $chunks);
        $this->assertSame('A', $chunks[0]);
        $this->assertSame('B', $chunks[1]);
        $this->assertSame($content, implode('', $chunks));
    }
}
