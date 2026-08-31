<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Controller;

use App\Auth\Infrastructure\Service\KeycloakService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth/logout', name: 'auth_logout', methods: ['POST'])]
class LogoutController
{
    public function __construct(
        private readonly KeycloakService $keycloak,
    ) {}

    public function __invoke(Request $request): Response
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $refreshToken = (string) ($data['refresh_token'] ?? '');

        if ($refreshToken !== '') {
            try {
                $this->keycloak->revokeToken($refreshToken);
            } catch (\Throwable) {
                // Best-effort: client should discard tokens regardless
            }
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
