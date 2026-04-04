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
}
