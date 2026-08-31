<?php

declare(strict_types=1);

namespace App\GeoStories\Domain;

interface GeoStoryLikeRepository
{
    public function find(string $userId, string $geoStoryId): ?GeoStoryLike;

    /** @return string[] ids de geostories a las que el usuario ha dado like */
    public function findIdsByUser(string $userId): array;

    public function countFor(string $geoStoryId): int;

    public function save(GeoStoryLike $like): void;

    public function delete(GeoStoryLike $like): void;
}
