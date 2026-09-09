<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\KeycloakTokenVerifier;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Lo que separa un token de un texto que dice ser un token.
 *
 * El realm se simula con un par de claves propio: así se puede firmar «bien» y
 * «mal» sin depender de que haya un Keycloak levantado.
 */
final class KeycloakTokenVerifierTest extends TestCase
{
    private const ISSUER = 'https://auth.example.test/realms/goveo';

    /** @var array{private: string, jwk: array<string, mixed>} */
    private array $realmKey;

    /** @var array{private: string, jwk: array<string, mixed>} */
    private array $otherKey;

    protected function setUp(): void
    {
        $this->realmKey = $this->generateKey('realm-key');
        $this->otherKey = $this->generateKey('other-key');
    }

    public function testAcceptsATokenSignedByTheRealm(): void
    {
        $payload = $this->verifier()->verify($this->token());

        self::assertSame('user-1', $payload['sub']);
        self::assertSame(['goveo-app' => ['roles' => ['user']]], $payload['resource_access']);
    }

    public function testRejectsATokenSignedByAnotherKey(): void
    {
        // El caso que importa: alguien se fabrica un token con los roles que
        // quiera y lo firma con una clave suya.
        $token = $this->token(key: $this->otherKey, claims: [
            'resource_access' => ['goveo-backoffice' => ['roles' => ['business.verify']]],
        ]);

        $this->expectException(AuthenticationException::class);
        $this->verifier()->verify($token);
    }

    public function testRejectsAnUnsignedToken(): void
    {
        $encode = static fn (array $data): string => rtrim(
            strtr(base64_encode((string) json_encode($data)), '+/', '-_'),
            '=',
        );

        $unsigned = $encode(['alg' => 'none', 'typ' => 'JWT'])
            . '.' . $encode(['sub' => 'atacante', 'iss' => self::ISSUER, 'azp' => 'goveo-app'])
            . '.';

        $this->expectException(AuthenticationException::class);
        $this->verifier()->verify($unsigned);
    }

    public function testRejectsAnExpiredToken(): void
    {
        // Más allá del margen por desfase de relojes.
        $token = $this->token(claims: ['exp' => time() - 3600]);

        $this->expectException(AuthenticationException::class);
        $this->verifier()->verify($token);
    }

    public function testRejectsAValidSignatureFromAnotherIssuer(): void
    {
        // Firma impecable, pero de otro Keycloak. Sin comprobar `iss`, cualquiera
        // podría montar el suyo y emitirse tokens para el nuestro.
        $token = $this->token(claims: ['iss' => 'https://auth.otro.test/realms/goveo']);

        $this->expectException(AuthenticationException::class);
        $this->verifier()->verify($token);
    }

    public function testRejectsATokenFromAClientWeDoNotKnow(): void
    {
        $token = $this->token(claims: ['azp' => 'una-integracion-cualquiera']);

        $this->expectException(AuthenticationException::class);
        $this->verifier()->verify($token);
    }

    public function testDoesNotHammerKeycloakWithUnknownKeyIds(): void
    {
        // Tokens basura con `kid` distintos: la primera vez se comprueba si el
        // realm ha rotado, las siguientes no. Si no, cualquiera podría usar el
        // BFF para lanzarle peticiones a Keycloak sin autenticarse.
        $requests = 0;
        $verifier = $this->verifier(counter: $requests);

        foreach (['inventado-1', 'inventado-2', 'inventado-3'] as $kid) {
            try {
                $verifier->verify($this->token(key: $this->generateKey($kid)));
                self::fail('Debería haber rechazado un token de una clave desconocida.');
            } catch (AuthenticationException) {
                // esperado
            }
        }

        self::assertSame(2, $requests, 'Una carga inicial y una sola recarga.');
    }

    public function testFetchesTheKeysAgainWhenTheRealmRotatesThem(): void
    {
        // Primera respuesta: las claves viejas, que no incluyen la que firmó el
        // token. Sin volver a preguntar, el BFF devolvería 401 a todo el mundo
        // hasta que caducara la caché.
        $rotated = $this->generateKey('rotated-key');

        $verifier = $this->verifier(
            jwksResponses: [
                ['keys' => [$this->realmKey['jwk']]],
                ['keys' => [$this->realmKey['jwk'], $rotated['jwk']]],
            ],
        );

        $payload = $verifier->verify($this->token(key: $rotated));

        self::assertSame('user-1', $payload['sub']);
    }

    /**
     * @param array<int, array<string, mixed>>|null $jwksResponses
     */
    private function verifier(?array $jwksResponses = null, int &$counter = 0): KeycloakTokenVerifier
    {
        // Un callable y no una lista: así el número de peticiones no está
        // limitado por el de respuestas preparadas, que es justo lo que hay que
        // poder medir.
        $bodies = $jwksResponses ?? [['keys' => [$this->realmKey['jwk']]]];

        $client = new MockHttpClient(static function () use ($bodies, &$counter): MockResponse {
            $body = $bodies[min($counter, count($bodies) - 1)];
            ++$counter;

            return new MockResponse((string) json_encode($body), [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });

        return new KeycloakTokenVerifier(
            $client,
            new ArrayAdapter(),
            new NullLogger(),
            'http://keycloak:8080',
            'https://auth.example.test',
            'goveo',
            ['goveo-app', 'goveo-backoffice'],
        );
    }

    /**
     * @param array{private: string, jwk: array<string, mixed>}|null $key
     * @param array<string, mixed>                                   $claims
     */
    private function token(?array $key = null, array $claims = []): string
    {
        $key ??= $this->realmKey;

        return JWT::encode(
            [
                'sub' => 'user-1',
                'iss' => self::ISSUER,
                'azp' => 'goveo-app',
                'exp' => time() + 300,
                'iat' => time(),
                'resource_access' => ['goveo-app' => ['roles' => ['user']]],
                ...$claims,
            ],
            $key['private'],
            'RS256',
            $key['jwk']['kid'],
        );
    }

    /**
     * @return array{private: string, jwk: array<string, mixed>}
     */
    private function generateKey(string $kid): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($resource);

        openssl_pkey_export($resource, $private);
        $details = openssl_pkey_get_details($resource);

        $base64Url = static fn (string $binary): string => rtrim(
            strtr(base64_encode($binary), '+/', '-_'),
            '=',
        );

        return [
            'private' => (string) $private,
            'jwk' => [
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => $kid,
                'n' => $base64Url($details['rsa']['n']),
                'e' => $base64Url($details['rsa']['e']),
            ],
        ];
    }
}
