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
     * @param ?string[] $categoryIds una o varias categorías. Varias porque la
     *                              home agrupa («comercio local» son casi
     *                              treinta), y una sola obligaría a pedir una
     *                              página por categoría y a mezclarlas fuera,
     *                              perdiendo el orden por cercanía.
     * @param ?string[] $excludeCategoryIds lo contrario: todo menos esas. Es lo
     *                              que pide «todo lo que no es turismo» sin
     *                              tener que enumerar las otras cuarenta.
     * @param ?float  $radiusMeters acota el resultado a ese radio (mapa: sólo lo
     *                              que entra en la vista). null = sin límite.
     * @param ?string $query        busca por nombre, sin tildes ni mayúsculas.
     */
    public function findNearby(
        float $latitude,
        float $longitude,
        int   $page,
        int   $size,
        ?array $categoryIds = null,
        ?array $excludeCategoryIds = null,
        ?float $radiusMeters = null,
        ?string $query = null,
    ): array;

    public function save(Business $business): void;
    public function delete(Business $business): void;
}
