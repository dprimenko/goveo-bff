<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Service;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Comprueba, por nuestra cuenta, de quién es un token social.
 *
 * Hace falta para poder vincular una cuenta antigua con su proveedor sin
 * preguntar (ver `SocialLoginController`): antes de tocar el usuario en Keycloak
 * hay que saber que el token es auténtico, que el correo está verificado y —lo
 * más importante— **que el token se emitió para nosotros**.
 *
 * Lo último no es una formalidad. Un token de acceso vale en cualquier sitio que
 * se limite a preguntarle al proveedor de quién es: quien opere otra aplicación
 * con acceso con Google podría coger el token de uno de sus usuarios y entrar
 * aquí como él. Por eso se mira la audiencia, y sin audiencia reconocible no se
 * vincula nada.
 */
final readonly class SocialTokenVerifier
{
    private const GOOGLE_TOKENINFO = 'https://oauth2.googleapis.com/tokeninfo';
    private const APPLE_KEYS       = 'https://appleid.apple.com/auth/keys';
    private const APPLE_ISSUER     = 'https://appleid.apple.com';

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private string $googleClientId,
        private string $googleAllowedAudiences,
        private string $appleClientId,
        private string $appleAllowedAudiences,
    ) {}

    /** La identidad que afirma el token, o null si no se puede dar por buena. */
    public function verify(string $provider, string $token): ?SocialIdentity
    {
        try {
            return match ($provider) {
                'google' => $this->verifyGoogle($token),
                'apple'  => $this->verifyApple($token),
                default  => null,
            };
        } catch (\Throwable $e) {
            $this->logger->warning('No se pudo verificar el token social', [
                'provider' => $provider,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Google emite un token de acceso opaco, así que se le pregunta a él.
     *
     * `tokeninfo` devuelve en una sola llamada lo que hace falta: el `sub`, el
     * correo, si está verificado y la audiencia. El endpoint de usuario, que es
     * el que usa Keycloak, no dice la audiencia.
     */
    private function verifyGoogle(string $token): ?SocialIdentity
    {
        $response = $this->httpClient->request('GET', self::GOOGLE_TOKENINFO, [
            'query' => ['access_token' => $token],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $info = $response->toArray(throw: false);

        $subject = (string) ($info['sub'] ?? '');
        $email   = (string) ($info['email'] ?? '');
        if ($subject === '' || $email === '') {
            return null;
        }

        // `azp` es el cliente que pidió el token; `aud` puede traer el mismo
        // valor o el del recurso. Basta con que uno de los dos sea nuestro.
        $audiences = array_filter([(string) ($info['aud'] ?? ''), (string) ($info['azp'] ?? '')]);
        if (!$this->audienceIsOurs($audiences, $this->googleAllowedAudiences, $this->googleClientId)) {
            $this->logger->warning('Token de Google con audiencia ajena', ['aud' => $audiences]);

            return null;
        }

        return new SocialIdentity($subject, $email, $this->isTrue($info['email_verified'] ?? false));
    }

    /**
     * Apple sólo entrega un `identity token`: un JWT firmado por Apple, que se
     * valida contra sus claves públicas sin hablar con nadie más.
     *
     * `JWT::decode` ya comprueba firma y caducidad; aquí se añade el emisor y la
     * audiencia, que la librería no mira.
     */
    private function verifyApple(string $token): ?SocialIdentity
    {
        $keys    = JWK::parseKeySet($this->appleKeySet());
        $payload = (array) JWT::decode($token, $keys);

        if (($payload['iss'] ?? '') !== self::APPLE_ISSUER) {
            return null;
        }

        $audiences = array_filter(array_map('strval', (array) ($payload['aud'] ?? [])));
        if (!$this->audienceIsOurs($audiences, $this->appleAllowedAudiences, $this->appleClientId)) {
            $this->logger->warning('Token de Apple con audiencia ajena', ['aud' => $audiences]);

            return null;
        }

        $subject = (string) ($payload['sub'] ?? '');
        $email   = (string) ($payload['email'] ?? '');
        if ($subject === '' || $email === '') {
            return null;
        }

        return new SocialIdentity($subject, $email, $this->isTrue($payload['email_verified'] ?? false));
    }

    /** @return array<string,mixed> */
    private function appleKeySet(): array
    {
        // Apple rota sus claves, pero no en cada login: pedirlas cada vez
        // convertiría su disponibilidad en la nuestra.
        return $this->cache->get('apple.jwks', function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            return $this->httpClient->request('GET', self::APPLE_KEYS)->toArray();
        });
    }

    /**
     * ¿La audiencia del token es una de las nuestras?
     *
     * Si hay lista explícita, manda ella. Si no, vale cualquier cliente del
     * mismo proyecto: los identificadores de Google comparten el número de
     * proyecto como prefijo (`940550817024-…`), y la app usa uno distinto en
     * iOS, en Android y en la web. Enumerarlos los tres en el entorno es fácil
     * de olvidar, y olvidarlo dejaría de vincular sin decir por qué.
     *
     * @param list<string> $audiences
     */
    private function audienceIsOurs(array $audiences, string $allowedList, string $ownClientId): bool
    {
        $allowed = array_filter(array_map('trim', explode(',', $allowedList)));

        if ($allowed !== []) {
            return array_intersect($audiences, $allowed) !== [];
        }

        if ($ownClientId === '') {
            return false;
        }

        if (in_array($ownClientId, $audiences, true)) {
            return true;
        }

        $project = strstr($ownClientId, '-', true);
        if ($project === false || $project === '') {
            return false;
        }

        foreach ($audiences as $audience) {
            if (str_starts_with($audience, $project . '-')) {
                return true;
            }
        }

        return false;
    }

    /** Google y Apple mandan los booleanos unas veces como tales y otras como cadena. */
    private function isTrue(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }
}
