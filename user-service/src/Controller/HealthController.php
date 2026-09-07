<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/', name: 'root', methods: ['GET'])]
    public function root(): JsonResponse
    {
        return new JsonResponse([
            'service' => 'user-service',
            'endpoints' => [
                'POST /api/users',
                'GET /api/users',
                'GET /api/users/{id}',
                'DELETE /api/users/{id}',
                'GET /health',
            ],
        ]);
    }
}
