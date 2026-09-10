<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use App\Business\Application\BusinessPurger;
use App\Business\Domain\BusinessRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * DELETE /api/admin/businesses/{id}
 *
 * Borra el negocio **y todo lo suyo**: productos, vídeos, subcategorías,
 * gestores, suscripciones, quién lo seguía, los vídeos en Bunny Stream y la
 * carpeta entera de imágenes en Bunny Storage. No hay vuelta atrás.
 *
 * Como en vídeos, dos frenos:
 *
 * - Permiso propio (`business.delete`), separado del de validar: quien decide
 *   qué se ve y quien puede destruir no tienen por qué ser la misma persona.
 * - **Sólo sobre lo ya archivado.** Un negocio vivo se archiva primero —eso es
 *   reversible— y se destruye después, si acaso.
 */
#[Route('/api/admin/businesses/{id}', name: 'admin_business_purge', methods: ['DELETE'])]
#[IsGranted('ROLE_BUSINESS_DELETE')]
class PurgeBusinessController
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly BusinessPurger $purger,
    ) {}

    public function __invoke(string $id): Response
    {
        $business = $this->businesses->findById($id);

        if ($business === null) {
            return new JsonResponse(['error' => 'Business not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$business->isDeleted()) {
            return new JsonResponse(
                ['error' => 'Only already-archived businesses can be purged.'],
                Response::HTTP_CONFLICT,
            );
        }

        // Se devuelve el recuento, y sobre todo si quedaba una suscripción
        // activa: Stripe no se entera de esto y seguiría cobrando.
        return new JsonResponse($this->purger->purge($business));
    }
}
