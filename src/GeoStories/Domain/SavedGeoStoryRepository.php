<?php

declare(strict_types=1);

namespace App\GeoStories\Domain;

interface SavedGeoStoryRepository
{
    /** Guarda el vídeo. Idempotente: si ya estaba, no hace nada. */
    public function save(string $userId, string $geoStoryId): void;

    /** Lo quita de Guardados. Idempotente. */
    public function remove(string $userId, string $geoStoryId): void;

    /** @return string[] ids de las geostories guardadas, la última primero */
    public function findIdsByUser(string $userId): array;
}
