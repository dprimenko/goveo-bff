<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /public/app-version — qué build de la app hace falta para seguir.
 *
 * Sustituye al Remote Config que usaba la app de Flutter, y existe por lo
 * mismo: cuando un cambio del servidor deja de ser compatible con lo que hay
 * instalado, hace falta una forma de decirlo **sin publicar otra versión**, que
 * es justo lo que no se puede hacer deprisa. Aquí se cambia una variable de
 * entorno y todas las apps se enteran en el siguiente arranque.
 *
 * **Se compara por número de build y no por `2.1.0`**, igual que se hacía en
 * Remote Config. El build es un entero que sólo sube, así que comparar es
 * comparar; con la versión de marketing habría que interpretar tres números,
 * decidir qué pasa con `2.10.0` frente a `2.9.0` y acordarse de subirla cuando
 * el cambio no la merece. En esta app, además, el `buildNumber` de iOS y el
 * `versionCode` de Android van igualados (ver `app.config.js`), así que un
 * mismo número significa lo mismo en las dos tiendas — pero se publican por
 * separado porque una tienda puede ir por detrás de la otra en revisión.
 *
 * ⚠️ **No alcanza a quien ya tiene una versión sin esta comprobación.** iOS no
 * ofrece ninguna forma de obligar a actualizar desde fuera, así que esto sólo
 * protege de la build que lo estrene en adelante. Para lo ya publicado, la
 * única palanca es que el servidor no le mande lo que no sabe pintar.
 *
 * Los nombres son los del Remote Config que sustituye (`requiredBuildNumberIOS`
 * / `requiredBuildNumberAndroid`), para que quien los cambiaba allí reconozca
 * qué está tocando aquí.
 *
 * **Sin configurar no bloquea a nadie**: el mínimo por defecto es `0`, que
 * cumple cualquiera. Un despliegue nuevo no empieza a echar gente por su
 * cuenta, que es lo que pasaría con el valor al revés.
 *
 * La ruta es pública **a propósito**: se pregunta antes de que nadie haya
 * podido identificarse, y una app bloqueada tiene que poder saberlo aunque su
 * sesión haya caducado.
 */
#[Route('/public/app-version', name: 'public_app_version', methods: ['GET'])]
final class AppVersionController
{
    public function __construct(
        private readonly int $requiredBuildIos,
        private readonly int $requiredBuildAndroid,
        private readonly string $storeUrlIos,
        private readonly string $storeUrlAndroid,
    ) {}

    public function __invoke(): Response
    {
        return new JsonResponse([
            'ios' => [
                'required_build' => $this->requiredBuildIos,
                'store_url'      => $this->storeUrlIos,
            ],
            'android' => [
                'required_build' => $this->requiredBuildAndroid,
                'store_url'      => $this->storeUrlAndroid,
            ],
        ]);
    }
}
