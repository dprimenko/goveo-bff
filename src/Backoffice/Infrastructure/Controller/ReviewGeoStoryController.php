<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use App\Backoffice\Application\ReviewDecisionMailer;
use App\GeoStories\Domain\GeoStoryRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * PUT /api/admin/geostories/{id}/approve
 * PUT /api/admin/geostories/{id}/reject
 * PUT /api/admin/geostories/{id}/remove
 * PUT /api/admin/geostories/{id}/restore
 *
 * Validar pone `verified_at`, que es lo único que decide si un vídeo se ve.
 * Retirar la validación lo quita: el vídeo **no se borra** —sigue estando y su
 * dueño lo sigue viendo en su perfil—, vuelve a «sin validar».
 *
 * Retirar y no borrar a propósito: lo que se retira suele ser discutible, no
 * delictivo, y destruir lo que alguien subió por una decisión que se puede
 * revisar mañana es desproporcionado. Borrar de verdad sigue siendo cosa de su
 * dueño, desde la app.
 *
 * **Las dos decisiones avisan a su dueño**: aprobar, con el enlace al vídeo ya
 * publicado; retirar, con los tres factores que hacen que un vídeo entre —lo que
 * se rechaza aquí casi siempre se arregla volviendo a grabar—. Sólo la primera
 * vez: la llamada se puede repetir, el correo no.
 */
#[IsGranted('ROLE_GEOSTORY_MODERATE')]
class ReviewGeoStoryController
{
    public function __construct(
        private readonly GeoStoryRepository $geoStories,
        private readonly ReviewDecisionMailer $mails,
    ) {}

    #[Route('/api/admin/geostories/{id}/approve', name: 'admin_geostory_approve', methods: ['PUT'])]
    public function approve(string $id): Response
    {
        return $this->decide($id, approve: true);
    }

    #[Route('/api/admin/geostories/{id}/reject', name: 'admin_geostory_reject', methods: ['PUT'])]
    public function reject(string $id): Response
    {
        return $this->decide($id, approve: false);
    }

    /**
     * Lo archiva sin destruirlo. Es lo que permite dejar la cola con
     * lo que de verdad hay que mirar: un vídeo que no se va a validar nunca
     * —una prueba, algo repetido— estorba ahí para siempre, y retirarlo no lo
     * saca de la cola porque «sin validar» es justo donde estaba.
     */
    #[Route('/api/admin/geostories/{id}/remove', name: 'admin_geostory_remove', methods: ['PUT'])]
    public function remove(string $id): Response
    {
        $story = $this->geoStories->findById($id);

        if ($story === null) {
            return new JsonResponse(['error' => 'GeoStory not found.'], Response::HTTP_NOT_FOUND);
        }

        $story->softDelete();
        $this->geoStories->save($story);

        return $this->state($story);
    }

    /** Y la vuelta: sin esto, archivar sería tan definitivo como borrar. */
    #[Route('/api/admin/geostories/{id}/restore', name: 'admin_geostory_restore', methods: ['PUT'])]
    public function restore(string $id): Response
    {
        $story = $this->geoStories->findById($id);

        if ($story === null) {
            return new JsonResponse(['error' => 'GeoStory not found.'], Response::HTTP_NOT_FOUND);
        }

        $story->restore();
        $this->geoStories->save($story);

        return $this->state($story);
    }

    private function decide(string $id, bool $approve): Response
    {
        $story = $this->geoStories->findById($id);

        if ($story === null) {
            return new JsonResponse(['error' => 'GeoStory not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($story->isDeleted()) {
            return new JsonResponse(['error' => 'GeoStory is deleted.'], Response::HTTP_CONFLICT);
        }

        // Aprobar algo que Bunny aún está codificando es aprobar a ciegas: no hay
        // vídeo que mirar todavía, y cuando lo haya ya estará publicado.
        if ($approve && $story->getStatus() !== 'ready') {
            return new JsonResponse(
                ['error' => 'GeoStory is not ready yet.', 'encoding' => $story->getStatus()],
                Response::HTTP_CONFLICT,
            );
        }

        // Aprobar es un cambio visible (`verified_at`), así que basta con
        // mirarlo. Retirar la validación deja el vídeo donde estaba —«sin
        // validar»—, y por eso el aviso se apunta en su `meta`: si no, la cola
        // lo sigue enseñando y el siguiente clic manda el correo otra vez.
        $told = $approve ? $story->isVerified() : $story->rejectionNoticeSent();

        if ($approve) {
            // Se borra el rastro para que un rechazo posterior vuelva a avisar.
            $story->verify()->clearRejectionNotice();
        } else {
            $story->unverify();

            if (!$told) {
                $story->markRejectionNoticeSent();
            }
        }

        $this->geoStories->save($story);

        if (!$told) {
            $approve
                ? $this->mails->videoApproved($story)
                : $this->mails->videoRejected($story);
        }

        return $this->state($story);
    }

    private function state(\App\GeoStories\Domain\GeoStory $story): JsonResponse
    {
        return new JsonResponse([
            'id'          => $story->getId(),
            'verified_at' => $story->getVerifiedAt()?->format(\DATE_ATOM),
            'deleted_at'  => $story->getDeletedAt()?->format(\DATE_ATOM),
        ]);
    }
}
