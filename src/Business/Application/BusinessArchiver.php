<?php

declare(strict_types=1);

namespace App\Business\Application;

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
 * **No toca la suscripción**, a propósito. Archivar es reversible y cancelar en
 * Stripe no lo es del todo, así que un archivado por error no puede acabar
 * costando una suscripción. Si hay que dejar de cobrar a un negocio archivado se
 * hace a mano en Stripe; el corte automático se queda para el borrado
 * definitivo, que ya no se deshace.
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
    ) {}

    /** @return array{products: int, videos: int} lo que se ha archivado con él */
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
        ];
    }

    /** @return array{products: int, videos: int} lo que ha vuelto con él */
    public function restore(Business $business): array
    {
        $at = $business->getDeletedAt()?->format('Y-m-d H:i:sP');

        $business->restore();
        $this->businesses->save($business);

        if ($at === null) {
            return ['products' => 0, 'videos' => 0];
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
        ];
    }
}
