<?php

declare(strict_types=1);

namespace App\Installs\Infrastructure\Controller;

use App\Installs\Application\InstallCounter;
use App\Installs\Domain\AttributedInstall;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /public/app-installs  `{platform, channel?, feature?, campaign?, kind?}`
 *
 * La app lo llama **una vez**, en la primera apertura tras instalar, cuando
 * Branch dice que llegó por un enlace (`+is_first_session` y
 * `+clicked_branch_link`). Es el recuento de «paso a la app» del panel (ver
 * `DashboardMetrics`).
 *
 * **Por qué aquí y no sólo en Google Analytics**: GA no ve a quien rechaza la
 * analítica —y en la primera apertura nadie ha contestado aún al aviso—, y no
 * une la visita de la web con la instalación de la app. Esto no identifica a
 * nadie (ver `AttributedInstall`), así que no necesita consentimiento.
 *
 * **Público por necesidad**: la primera apertura es anterior a cualquier
 * sesión. Responde 204 sin cuerpo; 400 sólo si no dice una plataforma que
 * conozcamos, que es la única forma de que no sea una instalación. Si la base
 * falla, también 204: la app no reintenta y no tiene nada que hacer con el
 * error; queda en el log.
 */
final class RecordAppInstallController
{
    public function __construct(
        private readonly InstallCounter $counter,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/public/app-installs', name: 'public_app_installs', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'invalid_json'], Response::HTTP_BAD_REQUEST);
        }

        $install = AttributedInstall::fromPayload($payload);
        if ($install === null) {
            return new JsonResponse(['error' => 'invalid_platform'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->counter->record($install, new \DateTimeImmutable());
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo contar una instalación atribuida: {message}', [
                'message' => $e->getMessage(),
            ]);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
