<?php

declare(strict_types=1);

namespace App\GeoStories\Domain;

interface GeoStoryRepository
{
    public function findById(string $id): ?GeoStory;

    /** Find by the Bunny Stream video GUID (for the transcoding webhook). */
    public function findByProviderVideoId(string $providerVideoId): ?GeoStory;

    /** @return GeoStory[] */
    public function findByInfluencerId(string $influencerId): array;

    /** @return GeoStory[] */
    public function findByBusinessId(string $businessId): array;

    /** @return GeoStory[] */
    public function findByCategoryId(string $categoryId): array;

    /**
     * Find geostories near a geographic point within a given radius (meters).
     *
     * @return GeoStory[]
     */
    public function findNearby(float $latitude, float $longitude, float $radiusMeters, int $limit = 20): array;

    /**
     * Equivalent to Supabase nearby_geostories SQL function.
     * Returns geostories with distance + joined influencer/business/category data,
     * ordered by proximity. Pass $ignoreId to exclude a specific geostory.
     *
     * @return GeoStoryWithDistance[]
     */
    public function findNearbyWithDetails(
        float $latitude,
        float $longitude,
        ?float $maxDistMeters = null,
        ?string $ignoreId = null,
        int $limit = 50,
    ): array;

    /**
     * Equivalent to Supabase geostory_with_distance SQL function.
     * Returns a single geostory with computed distance + joined data.
     */
    public function findByIdWithDistance(string $id, float $latitude, float $longitude): ?GeoStoryWithDistance;

    /**
     * Full feed query replacing the Supabase retrieveGeoStories use case.
     * Supports feedType-based category filtering, pagination, and entity filters.
     * Only returns verified geostories (verified_at IS NOT NULL).
     *
     * @return array{items: GeoStoryWithDistance[], total: int}
     */
    public function findFeed(
        float $latitude,
        float $longitude,
        int $page = 0,
        int $size = 10,
        ?float $maxDistMeters = null,
        ?string $ignoreId = null,
        ?string $feedType = null,
        ?string $categoryId = null,
        ?string $notCategoryId = null,
        ?string $businessId = null,
        ?string $influencerId = null,
        bool $includeUnverified = false,
        /**
         * Si quien pregunta sabe pintar una foto.
         *
         * Por defecto **no**: las apps publicadas antes de que existieran las
         * fotos montan un reproductor sobre lo que llegue, así que una foto se
         * les ve como un rectángulo negro. Quien sepa, lo dice (ver
         * `ListGeoStoriesController`).
         */
        bool $supportsImages = false,
        /** Subcategoría de un evento, por slug o id. */
        ?string $subcategory = null,
    ): array;

    /**
     * Los que siguen en `processing` y tienen vídeo en el proveedor, del más
     * antiguo al más nuevo: son los candidatos a haberse quedado atascados
     * porque su aviso se perdió. Ver `ReconcileProcessingGeoStoriesCommand`.
     *
     * @return GeoStory[]
     */
    public function findStuckProcessing(int $limit = 200): array;

    public function save(GeoStory $geoStory): void;
    public function delete(GeoStory $geoStory): void;
}
