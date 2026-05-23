<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\Strategy\BinaryFileResponseReflector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class BinaryFileResponseReflectorTest extends TestCase
{
    private string $testFile;

    protected function setUp(): void
    {
        $this->testFile = sys_get_temp_dir() . '/test_reflector_' . uniqid() . '.txt';
        file_put_contents($this->testFile, 'Hello World from binary file!');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testGetTempFileObjectReturnsNullWhenNotSet(): void
    {
        $reflector = new BinaryFileResponseReflector();
        $response = new BinaryFileResponse($this->testFile);

        $this->assertNull($reflector->getTempFileObject($response));
    }

    public function testGetTempFileObjectReturnsObjectWhenSet(): void
    {
        $reflector = new BinaryFileResponseReflector();
        $response = new BinaryFileResponse($this->testFile);

        $tempFile = new \SplTempFileObject();
        $tempFile->fwrite('Temp content');

        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('tempFileObject');
        $property->setValue($response, $tempFile);

        $result = $reflector->getTempFileObject($response);
        $this->assertInstanceOf(\SplTempFileObject::class, $result);
        $result->rewind();
        $this->assertSame('Temp content', $result->fread(1024));
    }

    public function testGetOffsetReturnsDefaultValueWhenNotSet(): void
    {
        $reflector = new BinaryFileResponseReflector();
        $response = new BinaryFileResponse($this->testFile);

        $this->assertSame(0, $reflector->getOffset($response));
    }

    public function testGetOffsetReturnsIntWhenSet(): void
    {
        $reflector = new BinaryFileResponseReflector();
        $response = new BinaryFileResponse($this->testFile);

        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('offset');
        $property->setValue($response, 42);

        $this->assertSame(42, $reflector->getOffset($response));
    }

    public function testGetMaxlenReturnsDefaultValueWhenNotSet(): void
    {
        $reflector = new BinaryFileResponseReflector();
        $response = new BinaryFileResponse($this->testFile);

        $this->assertSame(-1, $reflector->getMaxlen($response));
    }

    public function testGetMaxlenReturnsIntWhenSet(): void
    {
        $reflector = new BinaryFileResponseReflector();
        $response = new BinaryFileResponse($this->testFile);

        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('maxlen');
        $property->setValue($response, 100);

        $this->assertSame(100, $reflector->getMaxlen($response));
    }

    public function testGetDeleteFileAfterSendReturnsDefaultValueWhenNotSet(): void
    {
        $reflector = new BinaryFileResponseReflector();
        $response = new BinaryFileResponse($this->testFile);

        $this->assertFalse($reflector->getDeleteFileAfterSend($response));
    }

    public function testGetDeleteFileAfterSendReturnsBoolWhenSet(): void
    {
        $reflector = new BinaryFileResponseReflector();
        $response = new BinaryFileResponse($this->testFile);

        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($response, true);

        $this->assertTrue($reflector->getDeleteFileAfterSend($response));
    }

    public function testGetDeleteFileAfterSendReturnsFalseWhenSet(): void
    {
        $reflector = new BinaryFileResponseReflector();
        $response = new BinaryFileResponse($this->testFile);

        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($response, false);

        $this->assertFalse($reflector->getDeleteFileAfterSend($response));
    }

    public function testPropertyCacheIsUsedOnSubsequentCalls(): void
    {
        $reflector = new BinaryFileResponseReflector();
        $response = new BinaryFileResponse($this->testFile);

        $reflection = new \ReflectionClass($response);
        $offsetProperty = $reflection->getProperty('offset');
        $offsetProperty->setValue($response, 10);

        $this->assertSame(10, $reflector->getOffset($response));
        $this->assertSame(10, $reflector->getOffset($response));

        // Verify a second instance also returns cached result
        $reflector2 = new BinaryFileResponseReflector();
        $this->assertSame(10, $reflector2->getOffset($response));
    }
}
