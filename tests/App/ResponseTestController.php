<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

use CrazyGoat\WorkermanBundle\Protocol\Http\Response\StreamedBinaryFileResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ResponseTestController extends AbstractController
{
    #[Route('/response_test', name: 'app_response_test')]
    public function __invoke(): Response
    {
        return new Response(
            content: 'hello from test controller',
            headers: ['Content-Type' => 'text/plain'],
        );
    }

    #[Route('/response_test_json', name: 'app_response_test_json')]
    public function jsonResponse(): JsonResponse
    {
        return new JsonResponse(['hello' => 'world']);
    }

    #[Route('/response_test_file', name: 'app_response_test_file')]
    public function fileResponse(): BinaryFileResponse
    {
        $testFile = __DIR__ . '/../Fixtures/test_download.txt';

        return new BinaryFileResponse($testFile, \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="test_download.txt"',
        ]);
    }

    #[Route('/response_test_file_big', name: 'app_response_test_file_big')]
    public function bigFileResponse(): BinaryFileResponse
    {
        $path = sys_get_temp_dir() . '/wmb_big_file_test.bin';
        if (!is_file($path) || filesize($path) !== 3145728) {
            file_put_contents($path, str_repeat('0123456789abcdef', 196608));
        }

        return new BinaryFileResponse($path, Response::HTTP_OK, ['Content-Type' => 'application/octet-stream']);
    }

    #[Route('/response_test_file_delete', name: 'app_response_test_file_delete')]
    public function fileResponseWithDelete(): BinaryFileResponse
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_delete_');
        file_put_contents($tempFile, 'Delete me after download!');

        $response = new BinaryFileResponse($tempFile, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="delete_me.txt"',
        ]);

        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($response, true);

        return $response;
    }

    #[Route('/response_test_streamed_binary', name: 'app_response_test_streamed_binary')]
    public function streamedBinaryFileResponse(): StreamedBinaryFileResponse
    {
        $testFile = __DIR__ . '/../Fixtures/test_download.txt';

        return new StreamedBinaryFileResponse($testFile, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
        ]);
    }

    #[Route('/response_test_temp_file', name: 'app_response_test_temp_file')]
    public function tempFileResponse(): BinaryFileResponse
    {
        $tempFile = new \SplTempFileObject();
        $tempFile->fwrite('Temp file object content');

        $dummyFile = sys_get_temp_dir() . '/dummy_' . uniqid() . '.txt';
        file_put_contents($dummyFile, 'dummy');

        $response = new BinaryFileResponse($dummyFile, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="temp_file.txt"',
        ]);

        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('tempFileObject');
        $property->setValue($response, $tempFile);

        unlink($dummyFile);

        return $response;
    }

    #[Route('/response_test_file_not_modified', name: 'app_response_test_file_not_modified')]
    public function notModifiedFileResponse(): BinaryFileResponse
    {
        return new BinaryFileResponse(__DIR__ . '/../Fixtures/test_download.txt', Response::HTTP_NOT_MODIFIED, [
            'ETag' => '"test-download-etag"',
        ]);
    }

    #[Route('/response_test_file_no_content', name: 'app_response_test_file_no_content')]
    public function noContentFileResponse(): BinaryFileResponse
    {
        return new BinaryFileResponse(__DIR__ . '/../Fixtures/test_download.txt', Response::HTTP_NO_CONTENT);
    }

    #[Route('/dispatch_count_test', name: 'app_dispatch_count_test')]
    public function dispatchCountProbe(): Response
    {
        return new Response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }
}
