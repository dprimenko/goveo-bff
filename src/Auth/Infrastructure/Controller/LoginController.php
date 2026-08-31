<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Controller;

use App\Auth\Infrastructure\Service\KeycloakService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

#[Route('/api/auth/login', name: 'auth_login', methods: ['POST'])]
class LoginController
{
    public function __construct(
        private readonly KeycloakService $keycloak,
    ) {}

    public function __invoke(Request $request): Response
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $email    = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '') {
            return new JsonResponse(['error' => 'Email and password are required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $tokens = $this->keycloak->loginWithPassword($email, $password);
        } catch (ClientExceptionInterface $e) {
            $body = json_decode($e->getResponse()->getContent(throw: false), true) ?? [];
            $keycloakError = $body['error_description'] ?? $body['error'] ?? 'Invalid credentials.';

            return new JsonResponse(['error' => $keycloakError], Response::HTTP_UNAUTHORIZED);
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
