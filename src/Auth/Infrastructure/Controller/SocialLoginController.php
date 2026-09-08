<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Controller;

use App\Auth\Infrastructure\Service\KeycloakService;
use App\Auth\Infrastructure\Service\SocialTokenVerifier;
use Psr\Log\LoggerInterface;
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

    /**
     * Apple no entrega token de acceso: su SDK sólo devuelve el `identityToken`.
     * Google sí, y su token de acceso es lo que Keycloak sabe validar contra el
     * endpoint de usuario.
     */
    private const TOKEN_TYPES = [
        'google' => 'urn:ietf:params:oauth:token-type:access_token',
        'apple'  => 'urn:ietf:params:oauth:token-type:id_token',
    ];

    public function __construct(
        private readonly KeycloakService $keycloak,
        private readonly SocialTokenVerifier $verifier,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request, string $provider): Response
    {
        if (!in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            return new JsonResponse(['error' => sprintf('Provider "%s" is not supported.', $provider)], Response::HTTP_BAD_REQUEST);
        }

        $data  = json_decode($request->getContent(), true) ?? [];
        $token = (string) ($data['access_token'] ?? '');

        if ($token === '') {
            return new JsonResponse(['error' => 'access_token is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $tokens = $this->keycloak->loginWithSocialToken($provider, $token, self::TOKEN_TYPES[$provider]);
        } catch (ClientExceptionInterface $e) {
            $raw  = $e->getResponse()->getContent(throw: false);
            $body = json_decode($raw, true) ?? [];

            $tokens = $this->accountAlreadyExists($raw)
                ? $this->linkExistingAccountAndRetry($provider, $token)
                : null;

            if ($tokens === null) {
                $msg = $body['error_description'] ?? $body['error'] ?? 'Social login failed.';

                return new JsonResponse(['error' => $msg], Response::HTTP_UNAUTHORIZED);
            }
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

    /**
     * Keycloak encontró un usuario con ese correo, pero sin vínculo con el
     * proveedor, y abortó.
     *
     * Se busca por el texto y no por el código porque el cuerpo del error no
     * siempre trae el mismo: el evento del servidor lo llama
     * `federated_identity_account_exists` y la respuesta, según versión, «User
     * already exists».
     */
    private function accountAlreadyExists(string $body): bool
    {
        return str_contains($body, 'federated_identity')
            || str_contains($body, 'already exists');
    }

    /**
     * Vincula la cuenta que ya existe con el proveedor y reintenta el canje.
     *
     * Es lo que el realm hace solo en el login por navegador —el flujo «goveo
     * auto link»—, que por `token-exchange` no llega a ejecutarse. Sin esto, los
     * usuarios que vienen de Firebase con acceso por Google o Apple no pueden
     * entrar: su correo está en Keycloak, pero el vínculo con el proveedor nunca
     * se migró (lo rellena `goveo:migrate:firebase-social-links`). También cubre
     * a quien se registró aquí con contraseña y luego pulsa «entrar con Google».
     *
     * Sólo se vincula con un correo **verificado por el proveedor**: es lo que
     * hace equivalentes las dos cuentas. Sin esa garantía, cualquiera podría
     * apropiarse de una cuenta ajena registrando ese correo en el proveedor.
     *
     * @return array<string,mixed>|null los tokens del realm, o null si no procede
     */
    private function linkExistingAccountAndRetry(string $provider, string $token): ?array
    {
        $identity = $this->verifier->verify($provider, $token);
        if ($identity === null || !$identity->emailVerified) {
            return null;
        }

        $userId = $this->keycloak->findUserIdByEmail($identity->email);
        if ($userId === null) {
            return null;
        }

        // Si ya hay vínculo con **otro** sub, esto no es una cuenta sin migrar:
        // son dos identidades distintas compartiendo correo. Pisar el vínculo
        // dejaría fuera al dueño anterior, así que se deja el error como estaba.
        if ($this->keycloak->findFederatedIdentity($userId, $provider) !== null) {
            return null;
        }

        if (!$this->keycloak->linkFederatedIdentity($userId, $provider, $identity->subject, $identity->email)) {
            return null;
        }

        $this->logger->info('Cuenta vinculada con su proveedor social al entrar', [
            'provider' => $provider,
            'user'     => $userId,
        ]);

        try {
            return $this->keycloak->loginWithSocialToken($provider, $token, self::TOKEN_TYPES[$provider]);
        } catch (\Throwable $e) {
            $this->logger->error('El canje falló después de vincular la cuenta', [
                'provider' => $provider,
                'user'     => $userId,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }
}
