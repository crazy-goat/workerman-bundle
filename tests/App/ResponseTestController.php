<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

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
        $testFile = __DIR__ . '/../fixtures/test_download.txt';

        return new BinaryFileResponse($testFile, \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="test_download.txt"',
        ]);
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

        // Store temp file path in header for testing cleanup
        // The strategy will create a temp file from SplTempFileObject
        // We can't know the exact path, but we can verify cleanup works
        // by checking that no workerman_bundle_* files accumulate

        return $response;
    }

    #[Route('/response_test_temp_file_with_path', name: 'app_response_test_temp_file_with_path')]
    public function tempFileResponseWithPath(): Response
    {
        // Create a temp file that will be converted to physical file by strategy
        $tempFile = new \SplTempFileObject();
        $tempFile->fwrite('Temp file for cleanup test');

        // Create a dummy file path for BinaryFileResponse constructor
        $dummyFile = sys_get_temp_dir() . '/dummy_' . uniqid() . '.txt';
        file_put_contents($dummyFile, 'dummy');

        $binaryResponse = new BinaryFileResponse($dummyFile, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="temp_file.txt"',
        ]);

        // Use reflection to set tempFileObject
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('tempFileObject');
        $property->setValue($binaryResponse, $tempFile);

        // Clean up dummy file
        unlink($dummyFile);

        // Convert to get the temp file path
        $strategy = new \CrazyGoat\WorkermanBundle\Http\Response\Strategy\BinaryFileResponseStrategy();
        $workermanResponse = $strategy->convert($binaryResponse, []);

        // Get the temp file path from the response
        $tempFilePath = $workermanResponse->file['file'] ?? null;

        // Return a regular response with the temp file path in header
        return new Response(
            content: 'Temp file created',
            headers: [
                'Content-Type' => 'text/plain',
                'X-Temp-File-Path' => $tempFilePath ?? 'unknown',
            ],
        );
    }
}
