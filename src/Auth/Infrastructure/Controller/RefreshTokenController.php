<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Controller;

use App\Auth\Infrastructure\Service\KeycloakService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

#[Route('/api/auth/refresh', name: 'auth_refresh', methods: ['POST'])]
class RefreshTokenController
{
    public function __construct(
        private readonly KeycloakService $keycloak,
    ) {}

    public function __invoke(Request $request): Response
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $refreshToken = (string) ($data['refresh_token'] ?? '');

        if ($refreshToken === '') {
            return new JsonResponse(['error' => 'refresh_token is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $tokens = $this->keycloak->refreshToken($refreshToken);
        } catch (ClientExceptionInterface $e) {
            return new JsonResponse(['error' => 'Token refresh failed. Please log in again.'], Response::HTTP_UNAUTHORIZED);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Authentication service unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse([
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in'    => $tokens['expires_in'],
            'token_type'    => $tokens['token_type'] ?? 'Bearer',
        ]);
    }
}
