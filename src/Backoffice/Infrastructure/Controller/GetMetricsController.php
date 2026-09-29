<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use App\Backoffice\Application\Metrics\DashboardMetrics;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /api/admin/metrics — las cifras de la pantalla «Métricas» del panel.
 *
 * Qué mide cada una está en `DashboardMetrics` y, las de los negocios, en
 * `BusinessStatus`, que es donde se decide qué es «pendiente», «completo» o
 * «activo».
 *
 * Con permiso propio, `metrics.read`: son cifras del negocio —cuántos pagan,
 * cuántos se registran—, y no todo el que entra al panel a moderar vídeos tiene
 * por qué verlas.
 */
#[Route('/api/admin/metrics', name: 'admin_metrics', methods: ['GET'])]
#[IsGranted('ROLE_METRICS_READ')]
class GetMetricsController
{
    public function __construct(
        private readonly DashboardMetrics $metrics,
    ) {}

    public function __invoke(): Response
    {
        $response = new JsonResponse($this->metrics->at(new \DateTimeImmutable()));
        // Son cifras del momento: una copia en caché del navegador enseñaría las
        // de hace un rato como si fueran de ahora.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
