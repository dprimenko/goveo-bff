<?php

declare(strict_types=1);

namespace App\Business\Application;

use App\Billing\Application\SubscriptionCanceller;
use App\Business\Domain\Business;
use App\GeoStories\Infrastructure\Service\BunnyVideoService;
use App\Shared\Infrastructure\Storage\BunnyStorageService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Borra un negocio y todo lo que cuelga de él, sin vuelta atrás.
 *
 * El orden importa y va de fuera adentro: **primero lo que está en Bunny**, que
 * es lo único que no se puede recuperar buscando, y después las filas. Al revés,
 * un fallo a mitad dejaría vídeos e imágenes pagándose allí sin nada en la base
 * que los nombre — y ésos ya no los encuentra nadie.
 *
 * Las imágenes no se borran una a una sino **la carpeta entera** del negocio
 * (`business/{id}/`, con las de sus productos dentro): las que se fueron
 * sustituyendo al editar no están en ninguna fila, así que borrando sólo lo que
 * la base conoce se quedarían allí para siempre.
 *
 * **La suscripción se cancela en Stripe antes de borrar nada**, y en el acto:
 * aquí no hay vuelta atrás que preservar. Si Stripe fallara, el negocio se borra
 * igual —dejarlo a medio borrar es peor— y el resultado lo dice para poder
 * cancelarla a mano.
 */
final class BusinessPurger
{
    public function __construct(
        private readonly Connection $db,
        private readonly BunnyStorageService $storage,
        private readonly BunnyVideoService $videos,
        private readonly SubscriptionCanceller $subscriptions,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{
     *     products: int, videos: int, subcategories: int, managers: int,
     *     subscriptions: int, follows: int, likes: int,
     *     storage_deleted: bool, subscription: string
     * }
     */
    public function purge(Business $business): array
    {
        $id = $business->getId();

        // Lo primero, y antes de borrar la fila: después no habría con qué
        // saber qué cancelar en Stripe.
        $subscription = $this->subscriptions->cancelNow($id);

        // --- Bunny: vídeos uno a uno, imágenes de una tacada ---
        $videoIds = $this->db->fetchFirstColumn(
            'SELECT provider_video_id FROM geostories
              WHERE business_id = ? AND provider_video_id IS NOT NULL',
            [$id],
        );

        foreach ($videoIds as $videoId) {
            // `deleteVideo` se traga sus errores y los deja en el registro: que
            // Bunny falle no debe dejar el negocio a medio borrar en la base.
            $this->videos->deleteVideo((string) $videoId);
        }

        $storageDeleted = $this->storage->deleteBusinessFolder($id);

        // --- La base, de las hojas al tronco ---
        $counts = [
            // Los likes no tienen clave ajena declarada; sin esto quedan
            // apuntando a vídeos que ya no existen.
            'likes' => $this->db->executeStatement(
                'DELETE FROM geostory_likes
                  WHERE geostory_id IN (SELECT id FROM geostories WHERE business_id = ?)',
                [$id],
            ),
            'videos' => $this->db->executeStatement(
                'DELETE FROM geostories WHERE business_id = ?',
                [$id],
            ),
            'products' => $this->db->executeStatement(
                'DELETE FROM products WHERE business_id = ?',
                [$id],
            ),
            'subcategories' => $this->db->executeStatement(
                'DELETE FROM product_subcategories WHERE business_id = ?',
                [$id],
            ),
            'managers' => $this->db->executeStatement(
                'DELETE FROM business_managers WHERE business_id = ?',
                [$id],
            ),
            'subscriptions' => $this->db->executeStatement(
                'DELETE FROM business_subscriptions WHERE business_id = ?',
                [$id],
            ),
            // Quien lo seguía deja de seguirlo: si no, su lista se queda con un
            // hueco que no se puede pintar ni quitar.
            'follows' => $this->db->executeStatement(
                "DELETE FROM user_follows WHERE target_type = 'business' AND target_id = ?",
                [$id],
            ),
        ];

        $this->db->executeStatement('DELETE FROM business WHERE id = ?', [$id]);

        $this->logger->warning('Negocio borrado definitivamente desde el panel', [
            'business' => $id,
            'slug'     => $business->getSlug(),
            'counts'   => $counts,
        ]);

        return $counts + [
            'storage_deleted' => $storageDeleted,
            'subscription'    => $subscription,
        ];
    }
}
