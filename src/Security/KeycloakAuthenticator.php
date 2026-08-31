<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class KeycloakAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly string $keycloakUrl,
        private readonly string $keycloakRealm,
        private readonly string $keycloakClientId,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization')
            && str_starts_with((string) $request->headers->get('Authorization'), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $token = $this->extractToken($request);
        $payload = $this->decodeJwt($token);

        if (!isset($payload['sub'])) {
            throw new AuthenticationException('Invalid JWT: missing subject claim.');
        }

        $userId = $payload['sub'];
        $email = $payload['email'] ?? null;
        $roles = $this->resolveRoles($payload);
        $firstName = $payload['given_name'] ?? null;
        $lastName = $payload['family_name'] ?? null;
        $name = $payload['name'] ?? null;

        return new SelfValidatingPassport(
            new UserBadge(
                $userId,
                fn () => new GoveoUser($userId, $email, $roles, $firstName, $lastName, $name),
            ),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null; // continue request
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    private function extractToken(Request $request): string
    {
        $header = (string) $request->headers->get('Authorization', '');
        return substr($header, 7); // Strip "Bearer "
    }

    /**
     * Decode JWT payload WITHOUT signature verification.
     * Signature validation should be done at the infrastructure level (Nginx/API Gateway)
     * or via Keycloak's introspection endpoint for high-security endpoints.
     */
    private function decodeJwt(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new AuthenticationException('Malformed JWT token.');
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), strict: false);
        if ($payload === false) {
            throw new AuthenticationException('Failed to decode JWT payload.');
        }

        $data = json_decode($payload, associative: true);
        if (!is_array($data)) {
            throw new AuthenticationException('Invalid JWT payload.');
        }

        return $data;
    }

    private function resolveRoles(array $payload): array
    {
        $roles = ['ROLE_USER'];

        // Keycloak realm roles
        $realmRoles = $payload['realm_access']['roles'] ?? [];
        foreach ($realmRoles as $role) {
            $roles[] = 'ROLE_' . strtoupper((string) $role);
        }

        // Keycloak client roles
        $clientRoles = $payload['resource_access'][$this->keycloakClientId]['roles'] ?? [];
        foreach ($clientRoles as $role) {
            $roles[] = 'ROLE_' . strtoupper((string) $role);
        }

        return array_unique($roles);
    }
}
