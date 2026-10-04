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
        // 3 MB: above the 2 MB limit where Workerman streams a file in chunks.
        $path = sys_get_temp_dir() . '/wmb_big_file_test.bin';
        if (!is_file($path) || filesize($path) !== 3145728) {
            file_put_contents($path, str_repeat('0123456789abcdef', 196608));
        }

        return new BinaryFileResponse($path, Response::HTTP_OK, ['Content-Type' => 'application/octet-stream']);
    }

    #[Route('/response_test_file_delete', name: 'app_response_test_file_delete')]
    public function fileResponseWithDelete(): BinaryFileResponse
    {
        // Create a temp file that should be deleted after send
        $tempFile = tempnam(sys_get_temp_dir(), 'test_delete_');
        file_put_contents($tempFile, 'Delete me after download!');

        $response = new BinaryFileResponse($tempFile, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="delete_me.txt"',
        ]);

        // Use reflection to set deleteFileAfterSend
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
        // Create a temp file object (in-memory)
        $tempFile = new \SplTempFileObject();
        $tempFile->fwrite('Temp file object content');

        // Create a dummy file path for BinaryFileResponse constructor
        $dummyFile = sys_get_temp_dir() . '/dummy_' . uniqid() . '.txt';
        file_put_contents($dummyFile, 'dummy');

        $response = new BinaryFileResponse($dummyFile, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="temp_file.txt"',
        ]);

        // Use reflection to set tempFileObject
        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('tempFileObject');
        $property->setValue($response, $tempFile);

        // Clean up dummy file
        unlink($dummyFile);

        return $response;
    }

    /**
     * A file response whose status Symfony turns into a bodyless one:
     * prepare() sets maxlen = 0 for an empty status (RFC 9110 §15.4.5), so
     * the file must not follow the head (issue #948).
     */
    #[Route('/response_test_file_not_modified', name: 'app_response_test_file_not_modified')]
    public function notModifiedFileResponse(): BinaryFileResponse
    {
        return new BinaryFileResponse(__DIR__ . '/../Fixtures/test_download.txt', Response::HTTP_NOT_MODIFIED, [
            'ETag' => '"test-download-etag"',
        ]);
    }

    /**
     * The same for a 204, which must not carry content at all
     * (RFC 9110 §15.3.5) — issue #948.
     */
    #[Route('/response_test_file_no_content', name: 'app_response_test_file_no_content')]
    public function noContentFileResponse(): BinaryFileResponse
    {
        return new BinaryFileResponse(__DIR__ . '/../Fixtures/test_download.txt', Response::HTTP_NO_CONTENT);
    }

    /**
     * Minimal endpoint used by MiddlewareDispatchContractTest to verify the
     * middleware pipeline dispatches exactly once per request. The middleware
     * chain is solely responsible for tagging X-Dispatch-Count on the
     * response; the controller body is intentionally trivial.
     */
    #[Route('/dispatch_count_test', name: 'app_dispatch_count_test')]
    public function dispatchCountProbe(): Response
    {
        return new Response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }
}
