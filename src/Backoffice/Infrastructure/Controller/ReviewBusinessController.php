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
 * PUT /api/admin/businesses/{id}/remove
 * PUT /api/admin/businesses/{id}/restore
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

    /**
     * Lo archiva: deja de estar en la cola y en la app, pero no se destruye
     * nada. Para lo que no se va a validar nunca —una prueba, un duplicado—,
     * porque rechazarlo lo deja en su propia pestaña acumulándose.
     */
    #[Route('/api/admin/businesses/{id}/remove', name: 'admin_business_remove', methods: ['PUT'])]
    public function remove(string $id): Response
    {
        $business = $this->businesses->findById($id);

        if ($business === null) {
            return new JsonResponse(['error' => 'Business not found.'], Response::HTTP_NOT_FOUND);
        }

        $business->softDelete();
        $this->businesses->save($business);

        return $this->state($business);
    }

    /** Y la vuelta: sin esto, archivar sería tan definitivo como borrar. */
    #[Route('/api/admin/businesses/{id}/restore', name: 'admin_business_restore', methods: ['PUT'])]
    public function restore(string $id): Response
    {
        $business = $this->businesses->findById($id);

        if ($business === null) {
            return new JsonResponse(['error' => 'Business not found.'], Response::HTTP_NOT_FOUND);
        }

        $business->restore();
        $this->businesses->save($business);

        return $this->state($business);
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

        return $this->state($business);
    }

    private function state(\App\Business\Domain\Business $business): JsonResponse
    {
        return new JsonResponse([
            'id'          => $business->getId(),
            'verified_at' => $business->getVerifiedAt()?->format(\DATE_ATOM),
            'rejected_at' => $business->getRejectedAt()?->format(\DATE_ATOM),
            'deleted_at'  => $business->getDeletedAt()?->format(\DATE_ATOM),
        ]);
    }
}
