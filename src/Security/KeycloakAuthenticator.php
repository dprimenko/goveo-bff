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
        private readonly KeycloakTokenVerifier $verifier,
        private readonly string $keycloakClientId,
    ) {}

    /**
     * Rutas donde el token es **un extra, no un requisito**.
     *
     * En `/public/` la respuesta existe sin identificarse; el token sólo añade
     * cosas (los vídeos sin validar de tu propio perfil, por ejemplo). Aun así,
     * un token caducado hacía fallar **toda** la petición con un 401, y eso en
     * la app se veía como una pantalla sin datos: ni feed, ni negocios, ni nada
     * en ninguna pestaña. Quien ni siquiera había entrado lo veía todo; quien
     * tenía una sesión vieja, nada.
     *
     * Así que aquí el token malo se ignora y la petición sigue como anónima.
     */
    private const OPTIONAL_TOKEN_PATHS = ['/public/'];

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization')
            && str_starts_with((string) $request->headers->get('Authorization'), 'Bearer ');
    }

    /** Si en esta ruta un token inválido debe tumbar la petición o ignorarse. */
    private function tokenIsOptional(Request $request): bool
    {
        foreach (self::OPTIONAL_TOKEN_PATHS as $prefix) {
            if (str_starts_with($request->getPathInfo(), $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function authenticate(Request $request): Passport
    {
        $token = $this->extractToken($request);

        // Verificado de verdad —firma, caducidad, emisor y cliente— antes de
        // creerse una sola línea de lo que hay dentro. Ver KeycloakTokenVerifier.
        $payload = $this->verifier->verify($token);

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
        // `null` = sigue la petición sin usuario. En una ruta pública eso es
        // exactamente lo que se quiere: lo que se pueda ver sin sesión, se ve.
        if ($this->tokenIsOptional($request)) {
            return null;
        }

        return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    private function extractToken(Request $request): string
    {
        $header = (string) $request->headers->get('Authorization', '');
        return substr($header, 7); // Strip "Bearer "
    }

    private function resolveRoles(array $payload): array
    {
        $roles = ['ROLE_USER'];

        // Keycloak realm roles
        $realmRoles = $payload['realm_access']['roles'] ?? [];
        foreach ($realmRoles as $role) {
            $roles[] = $this->toSymfonyRole((string) $role);
        }

        // Roles del cliente que emitió el token (`azp`), no del que lleve la
        // configuración: con más de un cliente en el realm —la app y el
        // backoffice— mirar sólo el configurado dejaría al panel sin permisos.
        // Cada token trae los suyos y no los del otro cliente, que es
        // justo lo que se quiere.
        $client = $payload['azp'] ?? $this->keycloakClientId;
        $clientRoles = $payload['resource_access'][$client]['roles'] ?? [];
        foreach ($clientRoles as $role) {
            $roles[] = $this->toSymfonyRole((string) $role);
        }

        return array_unique($roles);
    }

    /**
     * `business.verify` → `ROLE_BUSINESS_VERIFY`.
     *
     * Los permisos del panel se nombran `recurso.acción` para que se lean en el
     * dashboard de Keycloak; Symfony los quiere en mayúsculas y sin puntos.
     */
    private function toSymfonyRole(string $role): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9]+/', '_', $role) ?? $role;

        return 'ROLE_' . strtoupper(trim($normalized, '_'));
    }
}
