<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\Strategy\StreamedResponseStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StreamedResponseStrategyTest extends TestCase
{
    public function testSupportsReturnsTrueForStreamedResponse(): void
    {
        $strategy = new StreamedResponseStrategy();

        $this->assertTrue($strategy->supports(new StreamedResponse()));
    }

    public function testSupportsReturnsTrueForStreamedJsonResponse(): void
    {
        $strategy = new StreamedResponseStrategy();

        $this->assertTrue($strategy->supports(new StreamedJsonResponse(['data'])));
    }

    public function testSupportsReturnsFalseForBinaryFileResponse(): void
    {
        $strategy = new StreamedResponseStrategy();
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'test');

        try {
            $this->assertFalse($strategy->supports(new BinaryFileResponse($tmpFile)));
        } finally {
            unlink($tmpFile);
        }
    }

    public function testSupportsReturnsFalseForRegularResponse(): void
    {
        $strategy = new StreamedResponseStrategy();

        $this->assertFalse($strategy->supports(new \Symfony\Component\HttpFoundation\Response()));
    }

    public function testConvertCapturesOutputBuffer(): void
    {
        $strategy = new StreamedResponseStrategy();
        $streamedResponse = new StreamedResponse(function () {
            echo 'streamed content';
        });

        $workermanResponse = $strategy->convert($streamedResponse, ['Content-Type' => ['text/plain']]);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame('streamed content', (string) $workermanResponse->rawBody());
    }

    public function testConvertHandlesEmptyStream(): void
    {
        $strategy = new StreamedResponseStrategy();
        $streamedResponse = new StreamedResponse(function () {
            // Empty callback
        });

        $workermanResponse = $strategy->convert($streamedResponse, []);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame('', (string) $workermanResponse->rawBody());
    }

    public function testConvertPreservesStatusCode(): void
    {
        $strategy = new StreamedResponseStrategy();
        $streamedResponse = new StreamedResponse(function () {
            echo 'data';
        }, 201);

        $workermanResponse = $strategy->convert($streamedResponse, []);

        $this->assertSame(201, $workermanResponse->getStatusCode());
    }
}
