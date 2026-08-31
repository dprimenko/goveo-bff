<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Service;

use App\GeoStories\Domain\GeoStoryLikeRepository;
use App\GeoStories\Domain\GeoStoryRepository;

/**
 * Likes que se publican en la API = base heredada + likes nuevos.
 *
 * `geostories.likes` es el acumulado que trajo el import desde la app Flutter,
 * donde no había registro por usuario. Se conserva como suelo y encima se suman
 * los de `geostory_likes`, para que el número no baje de golpe al desplegar y
 * para que dar/quitar like mueva el contador en ±1.
 */
final class GeoStoryLikeCounter
{
    public function __construct(
        private readonly GeoStoryLikeRepository $likes,
        private readonly GeoStoryRepository $stories,
    ) {}

    public function resolve(string $geoStoryId, ?int $legacyLikes = null): int
    {
        $base = $legacyLikes ?? $this->stories->findById($geoStoryId)?->getLikes() ?? 0;

        return $base + $this->likes->countFor($geoStoryId);
    }
}
