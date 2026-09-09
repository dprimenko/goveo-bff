<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use App\Business\Domain\BusinessRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * PUT /api/admin/businesses/{id}/approve
 * PUT /api/admin/businesses/{id}/reject
 *
 * Las dos decisiones de la cola de revisión, y las dos se pueden deshacer:
 * aprobar limpia el rechazo y rechazar retira la validación. Quien revisa se
 * equivoca, y arreglarlo no puede exigir tocar la base a mano.
 *
 * `PUT` y no `POST` porque el resultado es el mismo se llame una vez o cinco:
 * aprobar lo ya aprobado deja el negocio aprobado.
 */
#[IsGranted('ROLE_BUSINESS_VERIFY')]
class ReviewBusinessController
{
    public function __construct(
        private readonly BusinessRepository $businesses,
    ) {}

    #[Route('/api/admin/businesses/{id}/approve', name: 'admin_business_approve', methods: ['PUT'])]
    public function approve(string $id): Response
    {
        return $this->decide($id, approve: true);
    }

    #[Route('/api/admin/businesses/{id}/reject', name: 'admin_business_reject', methods: ['PUT'])]
    public function reject(string $id): Response
    {
        return $this->decide($id, approve: false);
    }

    private function decide(string $id, bool $approve): Response
    {
        $business = $this->businesses->findById($id);

        if ($business === null) {
            return new JsonResponse(['error' => 'Business not found.'], Response::HTTP_NOT_FOUND);
        }

        // Un negocio dado de baja no vuelve por aquí: la cola no lo enseña, y
        // aprobarlo por su id lo devolvería al feed sin que nadie lo pidiera.
        if ($business->isDeleted()) {
            return new JsonResponse(['error' => 'Business is deleted.'], Response::HTTP_CONFLICT);
        }

        $approve ? $business->verify() : $business->reject();
        $this->businesses->save($business);

        return new JsonResponse([
            'id'          => $business->getId(),
            'verified_at' => $business->getVerifiedAt()?->format(\DATE_ATOM),
            'rejected_at' => $business->getRejectedAt()?->format(\DATE_ATOM),
        ]);
    }
}
