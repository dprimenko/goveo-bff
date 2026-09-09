<?php

declare(strict_types=1);

namespace App\Security;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Comprueba que un access token lo ha emitido **nuestro** Keycloak.
 *
 * Antes el BFF descodificaba el JWT sin mirar la firma y se fiaba de lo que
 * pusiera dentro, delegando la comprobación en «Nginx o el API Gateway», donde
 * no había nada. Con eso, cualquiera podía escribirse un token con
 * `realm_access.roles: ["admin"]` y entrar por `/api/admin`: la firma es lo
 * único que separa un token de un texto que dice ser un token.
 *
 * Se comprueban cuatro cosas, y las cuatro hacen falta:
 *
 * - **La firma**, contra las claves públicas del realm (JWKS).
 * - **`exp` / `nbf`**, con un margen corto por el desfase de relojes.
 * - **`iss`**, que sea nuestro realm: una firma válida de otro Keycloak sigue
 *   siendo válida, y sin esto valdría igual.
 * - **`azp`**, el cliente que lo pidió, contra la lista de los nuestros. Es lo
 *   que impide que un token de un cliente futuro —una integración, un script—
 *   sirva para entrar al panel.
 *
 * No se mira `aud` porque los tokens de este realm no la traen; `azp` cumple el
 * mismo papel aquí.
 */
final class KeycloakTokenVerifier
{
    /** Las claves del realm cambian de Pascuas a Ramos; una hora es de sobra. */
    private const JWKS_TTL = 3600;

    private const CACHE_KEY = 'keycloak.jwks';

    /**
     * Cuánto se espera como poco entre dos recargas de las claves.
     *
     * Sin este freno, un `kid` inventado basta para que el BFF vaya a preguntar
     * a Keycloak: mandando tokens basura con `kid` distintos se le puede tirar
     * encima todo el tráfico que uno quiera, y sin autenticarse para nada.
     */
    private const REFRESH_COOLDOWN = 60;

    private const COOLDOWN_KEY = 'keycloak.jwks.refreshed_at';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        /** Interna: por donde el BFF llega a Keycloak dentro de la red de docker. */
        private readonly string $keycloakUrl,
        /** Pública: la que Keycloak escribe como `iss`, se pida el token por donde se pida. */
        private readonly string $keycloakPublicUrl,
        private readonly string $keycloakRealm,
        /** @var string[] */
        private readonly array $allowedClients,
        /** Margen para el desfase de relojes entre máquinas. */
        private readonly int $leewaySeconds = 60,
    ) {}

    /**
     * @return array<string, mixed> el contenido del token, ya verificado
     *
     * @throws AuthenticationException si no se puede confiar en él
     */
    public function verify(string $token): array
    {
        JWT::$leeway = $this->leewaySeconds;

        $payload = $this->decode($token);

        $expectedIssuer = sprintf('%s/realms/%s', rtrim($this->keycloakPublicUrl, '/'), $this->keycloakRealm);
        if (($payload['iss'] ?? null) !== $expectedIssuer) {
            throw new AuthenticationException('Token issued by an unknown issuer.');
        }

        $client = $payload['azp'] ?? null;
        if (!is_string($client) || !in_array($client, $this->allowedClients, true)) {
            throw new AuthenticationException('Token issued for an unknown client.');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $token): array
    {
        $keys = $this->keys(refresh: false);

        // Si el `kid` del token no está entre las claves que tenemos, se vuelven
        // a pedir antes de rechazarlo: Keycloak rota sus claves, y esperar a que
        // caduque la caché sería hasta una hora devolviendo 401 a todo el mundo.
        $kid = $this->keyIdOf($token);
        if ($kid !== null && !isset($keys[$kid]) && $this->mayRefresh()) {
            $keys = $this->keys(refresh: true);
        }

        try {
            $decoded = JWT::decode($token, $keys);
        } catch (\Throwable $e) {
            // El motivo va al registro y no a la respuesta: a quien manda un
            // token inválido no se le cuenta cuál de las comprobaciones falló.
            $this->logger->info('Rejected JWT: {reason}', ['reason' => $e->getMessage()]);

            throw new AuthenticationException('Invalid token.');
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) json_encode($decoded), true);

        return $payload;
    }

    /**
     * Claves públicas del realm, cacheadas.
     *
     * @return array<string, Key>
     */
    private function keys(bool $refresh): array
    {
        if ($refresh) {
            $this->cache->delete(self::CACHE_KEY);
        }

        $jwks = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::JWKS_TTL);

            $url = sprintf(
                '%s/realms/%s/protocol/openid-connect/certs',
                rtrim($this->keycloakUrl, '/'),
                $this->keycloakRealm,
            );

            return $this->httpClient->request('GET', $url)->toArray();
        });

        try {
            return JWK::parseKeySet($jwks);
        } catch (\Throwable $e) {
            // Un JWKS ilegible cacheado dejaría el BFF sin autenticar a nadie
            // hasta que caducara, así que se tira y se falla claro.
            $this->cache->delete(self::CACHE_KEY);

            throw new AuthenticationException('Cannot read the realm signing keys: ' . $e->getMessage());
        }
    }

    /**
     * Deja recargar sólo si hace bastante de la última vez. Un token con un
     * `kid` que no existe se rechaza igual; lo único que se pierde es la prisa
     * en enterarse de una rotación, que como mucho tarda un minuto.
     */
    private function mayRefresh(): bool
    {
        $fresh = false;

        $this->cache->get(self::COOLDOWN_KEY, function (ItemInterface $item) use (&$fresh): int {
            $item->expiresAfter(self::REFRESH_COOLDOWN);
            $fresh = true;

            return time();
        });

        return $fresh;
    }

    /** El `kid` de la cabecera, sin fiarse de nada más de lo que hay dentro. */
    private function keyIdOf(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $header = json_decode(
            (string) base64_decode(strtr($parts[0], '-_', '+/'), strict: false),
            associative: true,
        );

        return is_array($header) && is_string($header['kid'] ?? null) ? $header['kid'] : null;
    }
}
