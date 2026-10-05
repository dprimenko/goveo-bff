<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Repository;

use App\GeoStories\Domain\SavedGeoStoryRepository;
use Doctrine\DBAL\Connection;

/**
 * En SQL y no con el EntityManager: guardar es un `ON CONFLICT DO NOTHING`, y
 * con leer-y-luego-insertar dos toques seguidos del marcador chocarían con la
 * clave primaria y uno devolvería 500.
 */
final class DoctrineSavedGeoStoryRepository implements SavedGeoStoryRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function save(string $userId, string $geoStoryId): void
    {
        $this->db->executeStatement(
            'INSERT INTO saved_geostories (user_id, geostory_id, created_at)
             VALUES (?, ?, NOW())
             ON CONFLICT (user_id, geostory_id) DO NOTHING',
            [$userId, $geoStoryId],
        );
    }

    public function remove(string $userId, string $geoStoryId): void
    {
        $this->db->executeStatement(
            'DELETE FROM saved_geostories WHERE user_id = ? AND geostory_id = ?',
            [$userId, $geoStoryId],
        );
    }

    public function findIdsByUser(string $userId): array
    {
        return $this->db->fetchFirstColumn(
            'SELECT geostory_id::text FROM saved_geostories WHERE user_id = ? ORDER BY created_at DESC',
            [$userId],
        );
    }
}
