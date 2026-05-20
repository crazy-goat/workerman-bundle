<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TestController
{
    #[Route('/health', name: 'health')]
    public function health(): Response
    {
        return new JsonResponse(['status' => 'ok', 'php_version' => \PHP_VERSION]);
    }
}
