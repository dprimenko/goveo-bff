<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Controller;

use App\Auth\Infrastructure\Service\KeycloakService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

/**
 * Social login via Keycloak Token Exchange.
 * Supports: google, apple (once Identity Providers are configured in Keycloak).
 *
 * POST /api/auth/social/{provider}
 * Body: { "access_token": "<provider_token>" }
 */
#[Route('/api/auth/social/{provider}', name: 'auth_social', methods: ['POST'])]
class SocialLoginController
{
    private const SUPPORTED_PROVIDERS = ['google', 'apple'];

    public function __construct(
        private readonly KeycloakService $keycloak,
    ) {}

    public function __invoke(Request $request, string $provider): Response
    {
        if (!in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            return new JsonResponse(['error' => sprintf('Provider "%s" is not supported.', $provider)], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $accessToken = (string) ($data['access_token'] ?? '');

        if ($accessToken === '') {
            return new JsonResponse(['error' => 'access_token is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $tokens = $this->keycloak->loginWithSocialToken($provider, $accessToken);
        } catch (ClientExceptionInterface $e) {
            $body = json_decode($e->getResponse()->getContent(throw: false), true) ?? [];
            $msg  = $body['error_description'] ?? $body['error'] ?? 'Social login failed.';

            return new JsonResponse(['error' => $msg], Response::HTTP_UNAUTHORIZED);
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
