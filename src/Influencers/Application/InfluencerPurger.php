<?php

declare(strict_types=1);

namespace App\Influencers\Application;

use App\GeoStories\Infrastructure\Service\BunnyVideoService;
use App\Influencers\Domain\Influencer;
use App\Shared\Infrastructure\Storage\BunnyStorageService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Borra un influencer y lo que ha publicado, sin vuelta atrás.
 *
 * Como `BusinessPurger`: **primero Bunny** (vídeos, fotos y su carpeta), que es
 * lo único que no se encuentra buscando, y después las filas, de las hojas al
 * tronco.
 *
 * **La cuenta de usuario se queda.** El influencer es su perfil público; la
 * cuenta es de una persona, que puede seguir usando la app, tener negocios o
 * volver a ser influencer. Borrarla es otra decisión («Borrar cuenta»).
 */
final class InfluencerPurger
{
    public function __construct(
        private readonly Connection $db,
        private readonly BunnyStorageService $storage,
        private readonly BunnyVideoService $videos,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return array{videos: int, likes: int, saved: int, follows: int, blocks: int, reports: int, storage_deleted: bool} */
    public function purge(Influencer $influencer): array
    {
        $id = $influencer->getId();

        $videoIds = $this->db->fetchFirstColumn(
            'SELECT provider_video_id FROM geostories
              WHERE influencer_id = ? AND provider_video_id IS NOT NULL',
            [$id],
        );
        foreach ($videoIds as $videoId) {
            // Se traga sus errores: un fallo de Bunny no deja el borrado a medias.
            $this->videos->deleteVideo((string) $videoId);
        }

        $images = $this->db->fetchAllAssociative(
            "SELECT url, thumbnail FROM geostories WHERE influencer_id = ? AND media_type = 'image'",
            [$id],
        );
        foreach ($images as $image) {
            $this->storage->deleteByUrl($image['url']);
            if ($image['thumbnail'] !== $image['url']) {
                $this->storage->deleteByUrl($image['thumbnail']);
            }
        }

        $storageDeleted = $this->storage->deleteInfluencerFolder($id);

        $counts = [
            'likes' => $this->db->executeStatement(
                'DELETE FROM geostory_likes
                  WHERE geostory_id IN (SELECT id FROM geostories WHERE influencer_id = ?)',
                [$id],
            ),
            'saved' => $this->db->executeStatement(
                'DELETE FROM saved_geostories
                  WHERE geostory_id IN (SELECT id FROM geostories WHERE influencer_id = ?)',
                [$id],
            ),
            'videos' => $this->db->executeStatement('DELETE FROM geostories WHERE influencer_id = ?', [$id]),
            // Sin clave ajena: si no, quedan seguimientos, bloqueos y denuncias
            // apuntando a un perfil que ya no se puede pintar.
            'follows' => $this->db->executeStatement(
                "DELETE FROM user_follows WHERE target_type = 'influencer' AND target_id = ?",
                [$id],
            ),
            'blocks' => $this->db->executeStatement(
                "DELETE FROM user_blocks WHERE target_type = 'influencer' AND target_id = ?",
                [$id],
            ),
            'reports' => $this->db->executeStatement(
                "DELETE FROM content_reports WHERE target_type = 'influencer' AND target_id = ?",
                [$id],
            ),
        ];

        $this->db->executeStatement('DELETE FROM influencers WHERE id = ?', [$id]);

        $this->logger->warning('Influencer borrado definitivamente desde el panel', [
            'influencer' => $id,
            'username'   => $influencer->getUsername(),
            'counts'     => $counts,
        ]);

        return $counts + ['storage_deleted' => $storageDeleted];
    }
}
