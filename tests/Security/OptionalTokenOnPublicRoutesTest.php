<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\KeycloakAuthenticator;
use App\Security\KeycloakTokenVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * **Un token caducado no puede apagar lo público.**
 *
 * En `/public/` la respuesta existe sin identificarse: el token sólo añade
 * cosas —los vídeos aún sin validar de tu propio perfil—. Pero el firewall
 * rechazaba la petición entera con un 401 en cuanto el token no valía, y en la
 * app eso se veía como una pantalla sin nada: ni feed, ni negocios, ni eventos,
 * en ninguna pestaña. Lo raro del síntoma es que **quien no había entrado nunca
 * lo veía todo**, y quien tenía una sesión vieja no veía nada.
 *
 * Lo de fuera de `/public/` sigue igual: ahí el token no es un extra.
 */
final class OptionalTokenOnPublicRoutesTest extends TestCase
{
    public function testABadTokenDoesNotBlockAPublicRoute(): void
    {
        $authenticator = $this->authenticator();

        $respuesta = $authenticator->onAuthenticationFailure(
            Request::create('/public/businesses?lat=40.4&lng=-3.7'),
            new AuthenticationException('token caducado'),
        );

        // `null` = la petición sigue, sin usuario.
        self::assertNull($respuesta);
    }

    public function testABadTokenStillBlocksEverythingElse(): void
    {
        $authenticator = $this->authenticator();

        $respuesta = $authenticator->onAuthenticationFailure(
            Request::create('/api/follows'),
            new AuthenticationException('token caducado'),
        );

        self::assertNotNull($respuesta);
        self::assertSame(401, $respuesta->getStatusCode());
    }

    /**
     * El verificador se construye de verdad —es `final` y no se puede doblar—
     * pero aquí no llega a usarse: lo que se prueba es qué hace el autenticador
     * **después** de que la verificación haya fallado.
     */
    private function authenticator(): KeycloakAuthenticator
    {
        $verifier = new KeycloakTokenVerifier(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(CacheInterface::class),
            new NullLogger(),
            'http://keycloak:8080',
            'https://auth.goveo.app',
            'goveo',
            ['goveo-app'],
        );

        return new KeycloakAuthenticator($verifier, 'goveo-app');
    }
}
