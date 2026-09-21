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
 *
 * **Rechazar retira la validación y aparta el vídeo**, las dos cosas juntas.
 * Sólo quitar `verified_at` lo dejaba donde ya estaba —«sin validar» es de
 * donde venía—, así que seguía en la cola y al siguiente repaso volvía a
 * aparecer para decidirlo otra vez. Nada se destruye: apartado es recuperable,
 * y borrar de verdad sigue siendo cosa de su dueño desde la app. Lo que se
 * rechaza suele ser discutible, no delictivo.
 *
 * **Archivar por su cuenta (`/remove`) no avisa de nada**: es para lo que no se
 * va a mirar más —una prueba, algo repetido—, no para decirle a alguien que su
 * vídeo no ha pasado la selección.
 *
 * **Las dos decisiones avisan a su dueño**: aprobar, con el enlace al vídeo ya
 * publicado; rechazar, con los tres factores que hacen que un vídeo entre —lo que
 * se rechaza aquí casi siempre se arregla volviendo a grabar—. Sólo la primera
 * vez: la llamada se puede repetir, el correo no.
 *
 * **Salvo retirar algo ya publicado**, que usa esta misma llamada y no manda
 * nada. El correo dice que el vídeo «no ha pasado la selección», y eso no es lo
 * que ha pasado: el vídeo se aprobó, se publicó y se quita después —muchas veces
 * porque lo pide su propio dueño, o porque ya no toca—. Decirle que le han
 * rechazado algo que llevaba semanas publicado confunde a quien lo recibe.
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

        // Un vídeo apartado se puede decidir igual: aprobarlo es justo cómo se
        // deshace un rechazo, y por eso ya no hay 409 por estar apartado.
        //
        // Aprobar algo que Bunny aún está codificando es aprobar a ciegas: no hay
        // vídeo que mirar todavía, y cuando lo haya ya estará publicado.
        if ($approve && $story->getStatus() !== 'ready') {
            return new JsonResponse(
                ['error' => 'GeoStory is not ready yet.', 'encoding' => $story->getStatus()],
                Response::HTTP_CONFLICT,
            );
        }

        // Aprobar es un cambio visible (`verified_at`), así que basta con
        // mirarlo. Rechazar no tiene fecha propia —«sin validar» es de donde
        // venía—, así que el aviso se apunta en su `meta`: sin eso, rechazar lo
        // ya rechazado volvería a mandar el mismo «necesita un ajuste».
        $told = $approve ? $story->isVerified() : $story->rejectionNoticeSent();

        // Retirar ≠ rechazar: si el vídeo estaba publicado, esto no es una
        // decisión sobre si entra, es quitar algo que ya entró. Sin correo.
        $retirada = !$approve && $story->isVerified();

        if ($approve) {
            // Se borra el rastro para que un rechazo posterior vuelva a avisar.
            $story->verify()->clearRejectionNotice();
            // Y vuelve del cajón: un vídeo se aprueba desde «apartados» cuando
            // quien revisa se desdice, y dejarlo apartado sería aprobarlo a
            // medias — validado y sin verse en ninguna parte.
            $story->restore();
        } else {
            $story->unverify()->softDelete();

            // No se apunta nada en una retirada: el aviso no se ha mandado, así
            // que si el vídeo vuelve y esta vez se rechaza de verdad, su dueño
            // tiene que enterarse.
            if (!$told && !$retirada) {
                $story->markRejectionNoticeSent();
            }
        }

        $this->geoStories->save($story);

        if (!$told && !$retirada) {
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
