<?php

declare(strict_types=1);

namespace App\Business\Domain;

interface BusinessRepository
{
    public function findById(string $id): ?Business;
    public function findBySlug(string $slug): ?Business;

    /** @return Business[] */
    public function findByCreatorId(string $creatorId): array;

    /**
     * Returns businesses ordered by distance to the given coordinates.
     * Distance is calculated from the nearest associated geostory location.
     *
     * @return array{items: array<array{business: Business, dist_meters: float}>, total: int}
     */
    /**
     * @param ?float  $radiusMeters acota el resultado a ese radio (mapa: sólo lo
     *                              que entra en la vista). null = sin límite.
     * @param ?string $query        busca por nombre, sin tildes ni mayúsculas.
     */
    public function findNearby(
        float $latitude,
        float $longitude,
        int   $page,
        int   $size,
        ?string $categoryId = null,
        ?float $radiusMeters = null,
        ?string $query = null,
    ): array;

    public function save(Business $business): void;
    public function delete(Business $business): void;
}
