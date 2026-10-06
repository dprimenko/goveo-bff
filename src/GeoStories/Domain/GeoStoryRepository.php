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
        /** Subcategoría de un evento, por slug o id. */
        ?string $subcategory = null,
        /**
         * Id local de quien mira, si hay sesión: se le quita lo de las cuentas
         * que ha bloqueado (ver `App\Moderation`).
         */
        ?string $viewerId = null,
        /**
         * `events`: fuera los eventos. La fila de vídeos de un perfil, que los
         * eventos tienen la suya (con `feedType=events`).
         */
        ?string $exclude = null,
        /** Pestaña de Eventos: el día (y hora) elegido en vez de hoy. */
        ?EventDay $eventDay = null,
        /** Subnivel de un evento (Musicales, Música clásica…), por slug o id. */
        ?string $subtype = null,
        /**
         * «Siguiendo»: sólo lo de los negocios e influencers que sigue
         * `$viewerId`. Sin quien mira, lista vacía.
         */
        bool $following = false,
    ): array;

    /**
     * Cuántos eventos enseñaría ahora la pestaña de Eventos por tipo y por
     * subnivel (claves: id de la categoría). Las mismas reglas que `findFeed`
     * con `feedType=events` —listo, validado, vigente o del día elegido, sin
     * las salas del scraping pendientes— y, si se da, el mismo radio (`maxDist`
     * de la app y la web). Es el número que sale junto a cada tipo en el
     * selector.
     *
     * @return array<string,int>
     */
    public function countEventsByType(
        ?EventDay $eventDay = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $maxDistMeters = null,
    ): array;

    /**
     * Los vídeos que ha guardado el usuario, el último guardado primero, con la
     * misma forma que el feed. Sólo lo que el feed enseñaría (listo, validado,
     * sin bloquear), pero **sin caducidad**: un evento guardado que ya pasó se
     * queda en Guardados.
     *
     * @return array{items: GeoStoryWithDistance[], total: int}
     */
    public function findSavedBy(
        string $userId,
        float $latitude,
        float $longitude,
        int $page = 0,
        int $size = 10,
        /** Una pestaña: `events`, `local`, `tourism` o `geostories`. */
        ?string $feedType = null,
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
