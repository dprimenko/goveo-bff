<?php

declare(strict_types=1);

namespace App\Business\Application;

use App\Billing\Application\SubscriptionCanceller;
use App\Business\Domain\Business;
use App\Business\Domain\BusinessRepository;
use Doctrine\DBAL\Connection;

/**
 * Archivar un negocio se lleva por delante lo que cuelga de él.
 *
 * Sin esto, dar de baja una tienda dejaba sus productos y sus vídeos vivos: los
 * vídeos seguían saliendo en el feed y en el mapa —el repositorio de geostories
 * no mira si el negocio existe— y los productos seguían respondiendo por su
 * enlace. El negocio desaparecía y su escaparate no.
 *
 * Y **deja de cobrarse**: al archivar se programa el corte para el final del
 * periodo ya pagado, y al recuperar se deshace esa marca (ver
 * `SubscriptionCanceller`).
 *
 * **La marca de tiempo es la misma para todo**, y ahí está el truco para poder
 * deshacerlo: al recuperar sólo se devuelven los que tienen exactamente esa
 * fecha, así que lo que su dueño ya había borrado antes se queda borrado. Sin
 * esa condición, recuperar un negocio resucitaría productos que nadie quería.
 */
final class BusinessArchiver
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly Connection $db,
        private readonly SubscriptionCanceller $subscriptions,
    ) {}

    /** @return array{products: int, videos: int, subscription: string} */
    public function archive(Business $business): array
    {
        $business->softDelete();
        $this->businesses->save($business);

        $at = $business->getDeletedAt()?->format('Y-m-d H:i:sP');

        return [
            'products' => $this->db->executeStatement(
                'UPDATE products SET deleted_at = ?, updated_at = ?
                  WHERE business_id = ? AND deleted_at IS NULL',
                [$at, $at, $business->getId()],
            ),
            'videos' => $this->db->executeStatement(
                'UPDATE geostories SET deleted_at = ?, updated_at = ?
                  WHERE business_id = ? AND deleted_at IS NULL',
                [$at, $at, $business->getId()],
            ),
            // Se deja de cobrar al acabar el periodo ya pagado. No en el acto:
            // esto se puede deshacer, y una suscripción cancelada no vuelve.
            'subscription' => $this->subscriptions->scheduleCancellation($business->getId()),
        ];
    }

    /** @return array{products: int, videos: int, subscription: string} */
    public function restore(Business $business): array
    {
        $at = $business->getDeletedAt()?->format('Y-m-d H:i:sP');

        $business->restore();
        $this->businesses->save($business);

        $subscription = $this->subscriptions->resume($business->getId());

        if ($at === null) {
            return ['products' => 0, 'videos' => 0, 'subscription' => $subscription];
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');

        return [
            'products' => $this->db->executeStatement(
                'UPDATE products SET deleted_at = NULL, updated_at = ?
                  WHERE business_id = ? AND deleted_at = ?',
                [$now, $business->getId(), $at],
            ),
            'videos' => $this->db->executeStatement(
                'UPDATE geostories SET deleted_at = NULL, updated_at = ?
                  WHERE business_id = ? AND deleted_at = ?',
                [$now, $business->getId(), $at],
            ),
            'subscription' => $subscription,
        ];
    }
}
