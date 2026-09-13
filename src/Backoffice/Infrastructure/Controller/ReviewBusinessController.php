<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Controller;

use App\Backoffice\Application\ReviewDecisionMailer;
use App\Business\Application\BusinessArchiver;
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
 * aprobar limpia el rechazo, y lo rechazado se recupera desde «borrados».
 * Quien revisa se equivoca, y arreglarlo no puede exigir tocar la base a mano.
 *
 * **Rechazar también archiva.** Son una sola cosa para quien revisa —el negocio
 * se descarta y desaparece de la cola—, y separarlas dejaba lo rechazado en
 * «pendientes» para siempre, porque «sin validar» es justo donde ya estaba. Va
 * junto aquí y no encadenando dos llamadas desde el panel: a medio camino
 * quedaría un negocio rechazado y aún en la cola, o archivado sin avisar a
 * nadie.
 *
 * **Archivar por su cuenta (`/remove`) no avisa de nada**, y es la diferencia
 * que importa: ese botón es para lo que no se va a mirar más —un duplicado, una
 * prueba, un negocio que se da de baja—, y decirle a alguien «tu solicitud no ha
 * sido aprobada» por limpiar un duplicado es peor que no decir nada.
 *
 * `PUT` y no `POST` porque el resultado es el mismo se llame una vez o cinco:
 * aprobar lo ya aprobado deja el negocio aprobado.
 *
 * **Y se avisa al dueño**, que antes no se hacía: aprobar sólo cambiaba una
 * fecha y el interesado se enteraba —si se enteraba— abriendo la app a ver si
 * ya salía. El correo se manda **sólo si la decisión cambia**: la llamada es
 * idempotente, pero el correo no puede serlo.
 */
#[IsGranted('ROLE_BUSINESS_VERIFY')]
class ReviewBusinessController
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly BusinessArchiver $archiver,
        private readonly ReviewDecisionMailer $mails,
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
     * Archiva **sin avisar a nadie**: deja de estar en la cola y en la app, pero
     * no se destruye nada. Es para lo que no se va a mirar más —una prueba, un
     * duplicado, un negocio que se da de baja—, no para decirle a su dueño que
     * no ha pasado la revisión; eso es `reject`, que además archiva.
     */
    #[Route('/api/admin/businesses/{id}/remove', name: 'admin_business_remove', methods: ['PUT'])]
    public function remove(string $id): Response
    {
        $business = $this->businesses->findById($id);

        if ($business === null) {
            return new JsonResponse(['error' => 'Business not found.'], Response::HTTP_NOT_FOUND);
        }

        // Se lleva sus productos y sus vídeos: si no, el negocio desaparecía y
        // su escaparate seguía en el feed y en el mapa (ver BusinessArchiver).
        $cascade = $this->archiver->archive($business);

        return $this->state($business, $cascade);
    }

    /** Y la vuelta: sin esto, archivar sería tan definitivo como borrar. */
    #[Route('/api/admin/businesses/{id}/restore', name: 'admin_business_restore', methods: ['PUT'])]
    public function restore(string $id): Response
    {
        $business = $this->businesses->findById($id);

        if ($business === null) {
            return new JsonResponse(['error' => 'Business not found.'], Response::HTTP_NOT_FOUND);
        }

        $cascade = $this->archiver->restore($business);

        return $this->state($business, $cascade);
    }

    private function decide(string $id, bool $approve): Response
    {
        $business = $this->businesses->findById($id);

        if ($business === null) {
            return new JsonResponse(['error' => 'Business not found.'], Response::HTTP_NOT_FOUND);
        }

        // Antes de tocar nada: es la diferencia entre revisar y volver a pulsar.
        $decided = $approve
            ? $business->getVerifiedAt() !== null
            : $business->getRejectedAt() !== null;

        $cascade = null;

        if ($approve) {
            $business->verify();
            $this->businesses->save($business);

            // Y lo saca del cajón, porque lo rechazado está archivado: validado
            // y archivado a la vez es validado a medias —no se ve en ninguna
            // parte—, y desdecirse no puede exigir dos pasos en dos pestañas.
            $cascade = $this->archiver->restore($business);
        } else {
            $business->reject();
            $this->businesses->save($business);

            // Y fuera de la cola, con sus productos y sus vídeos: si no, el
            // negocio quedaba rechazado y en «pendientes» a la vez, y su
            // escaparate seguía en el feed (ver BusinessArchiver).
            //
            // Repetirlo no molesta —archivar lo archivado no cambia nada— y es
            // lo que permite volver a pulsar sin que salte un error: lo que no
            // se repite es el correo.
            $cascade = $this->archiver->archive($business);
        }

        // Después de guardar: si el correo falla, la decisión ya está tomada
        // (y el mailer no lanza, ver ReviewDecisionMailer).
        if (!$decided) {
            $approve
                ? $this->mails->businessApproved($business)
                : $this->mails->businessRejected($business);
        }

        return $this->state($business, $cascade);
    }

    /** @param array{products: int, videos: int}|null $cascade */
    private function state(\App\Business\Domain\Business $business, ?array $cascade = null): JsonResponse
    {
        return new JsonResponse([
            'id'          => $business->getId(),
            'verified_at' => $business->getVerifiedAt()?->format(\DATE_ATOM),
            'rejected_at' => $business->getRejectedAt()?->format(\DATE_ATOM),
            'deleted_at'  => $business->getDeletedAt()?->format(\DATE_ATOM),
        ] + ($cascade === null ? [] : ['cascade' => $cascade]));
    }
}
