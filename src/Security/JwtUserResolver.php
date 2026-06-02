<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Extracts user information from the Keycloak JWT present in the Authorization header.
 * Use this in controllers to get the currently authenticated user's ID.
 */
class JwtUserResolver
{
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

        $token = substr($header, 7);
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [];
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), strict: false);
        if ($payload === false) {
            return [];
        }

        return json_decode($payload, associative: true) ?? [];
    }
}
