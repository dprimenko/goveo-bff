<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Controller;

use App\Auth\Infrastructure\Service\KeycloakService;
use App\Users\Domain\User;
use App\Users\Domain\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

#[Route('/api/auth/register', name: 'auth_register', methods: ['POST'])]
class RegisterController
{
    public function __construct(
        private readonly KeycloakService $keycloak,
        private readonly UserRepository  $userRepository,
    ) {}

    public function __invoke(Request $request): Response
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $email     = trim(strtolower((string) ($data['email'] ?? '')));
        $password  = (string) ($data['password'] ?? '');
        $firstName = trim((string) ($data['firstName'] ?? ''));
        $lastName  = trim((string) ($data['lastName'] ?? ''));

        if ($email === '' || $password === '') {
            return new JsonResponse(['error' => 'Email and password are required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Invalid email address.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (strlen($password) < 8) {
            return new JsonResponse(['error' => 'Password must be at least 8 characters.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            // 1. Create user in Keycloak — this is the source of truth for auth
            $keycloakId = $this->keycloak->registerUser($email, $password, $firstName, $lastName);

            // 2. Mirror user in local DB for relational data
            $user = new User(
                id:        $keycloakId,
                email:     $email,
                name:      trim($firstName . ' ' . $lastName) ?: null,
            );
            $this->userRepository->save($user);

            // 3. Immediately obtain tokens so the client is logged in
            $tokens = $this->keycloak->loginWithPassword($email, $password);

        } catch (ClientExceptionInterface $e) {
            $body = json_decode($e->getResponse()->getContent(throw: false), true) ?? [];
            $msg  = $body['errorMessage'] ?? $body['error_description'] ?? 'Registration failed.';

            // Keycloak returns 409 when the email already exists
            $code = $e->getResponse()->getStatusCode() === 409
                ? Response::HTTP_CONFLICT
                : Response::HTTP_BAD_REQUEST;

            return new JsonResponse(['error' => $msg], $code);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Registration service unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse([
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in'    => $tokens['expires_in'],
            'token_type'    => $tokens['token_type'] ?? 'Bearer',
        ], Response::HTTP_CREATED);
    }
}
