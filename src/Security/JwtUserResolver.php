<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Extracts user information from the Keycloak JWT present in the Authorization header.
 * Use this in controllers to get the currently authenticated user's ID.
 *
 * **Verifica el token igual que el firewall.** Antes lo descodificaba a pelo, y
 * aunque hoy sólo lo usan rutas que ya exigen sesión —el firewall corre antes
 * que el controlador—, dejaba abierta una segunda vía de leer el token sin
 * comprobar nada: el día que alguien lo usara en una ruta pública, el agujero
 * volvía sin que nadie lo tocara.
 */
class JwtUserResolver
{
    public function __construct(
        private readonly KeycloakTokenVerifier $verifier,
    ) {}

    public function resolveUserId(Request $request): ?string
    {
        $payload = $this->getPayload($request);
        return $payload['sub'] ?? null;
    }

    public function resolveEmail(Request $request): ?string
    {
        $payload = $this->getPayload($request);
        return $payload['email'] ?? null;
    }

    public function resolveRoles(Request $request): array
    {
        $payload = $this->getPayload($request);
        return $payload['realm_access']['roles'] ?? [];
    }

    public function resolveClaim(Request $request, string $claim): mixed
    {
        $payload = $this->getPayload($request);
        return $payload[$claim] ?? null;
    }

    private function getPayload(Request $request): array
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return [];
        }

        try {
            return $this->verifier->verify(substr($header, 7));
        } catch (AuthenticationException) {
            // Sin token válido no hay usuario, que es lo que este resolver
            // contesta ya para «no hay cabecera»: quien decide si eso es un 401
            // es el firewall, no esto.
            return [];
        }
    }
}
