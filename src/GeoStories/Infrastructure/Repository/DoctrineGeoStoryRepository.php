<?php

declare(strict_types=1);

namespace App\GeoStories\Infrastructure\Repository;

use App\GeoStories\Domain\GeoStory;
use App\GeoStories\Domain\GeoStoryRepository;
use App\GeoStories\Domain\GeoStoryWithDistance;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineGeoStoryRepository implements GeoStoryRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function findById(string $id): ?GeoStory
    {
        return $this->em->find(GeoStory::class, $id);
    }

    public function findByProviderVideoId(string $providerVideoId): ?GeoStory
    {
        return $this->em->getRepository(GeoStory::class)->findOneBy([
            'providerVideoId' => $providerVideoId,
            'deletedAt'       => null,
        ]);
    }

    public function findByInfluencerId(string $influencerId): array
    {
        return $this->em->getRepository(GeoStory::class)->findBy(
            ['influencerId' => $influencerId, 'deletedAt' => null],
            ['createdAt' => 'DESC'],
        );
    }

    public function findByBusinessId(string $businessId): array
    {
        return $this->em->getRepository(GeoStory::class)->findBy(
            ['businessId' => $businessId, 'deletedAt' => null],
            ['createdAt' => 'DESC'],
        );
    }

    public function findByCategoryId(string $categoryId): array
    {
        return $this->em->getRepository(GeoStory::class)->findBy(
            ['categoryId' => $categoryId, 'deletedAt' => null],
            ['createdAt' => 'DESC'],
        );
    }

    public function findNearby(float $latitude, float $longitude, float $radiusMeters, int $limit = 20): array
    {
        $sql = <<<'SQL'
            SELECT g.*
            FROM geostories g
            WHERE g.deleted_at IS NULL
              AND g.location IS NOT NULL
              AND ST_DWithin(
                    g.location::geography,
                    ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography,
                    :radius
                  )
            ORDER BY g.location <-> ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
            LIMIT :limit
        SQL;

        $conn = $this->em->getConnection();
        $result = $conn->executeQuery($sql, [
            'lat' => $latitude,
            'lng' => $longitude,
            'radius' => $radiusMeters,
            'limit' => $limit,
        ]);

        $rows = $result->fetchAllAssociative();
        $ids = array_column($rows, 'id');

        if (empty($ids)) {
            return [];
        }

        return $this->em->createQueryBuilder()
            ->select('g')
            ->from(GeoStory::class, 'g')
            ->where('g.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /** @return GeoStoryWithDistance[] */
    public function findNearbyWithDetails(
        float $latitude,
        float $longitude,
        ?float $maxDistMeters = null,
        ?string $ignoreId = null,
        int $limit = 50,
    ): array {
        $sql = <<<'SQL'
            SELECT
                geo.id,
                geo.title,
                geo.description,
                geo.thumbnail,
                geo.url,
                geo.status,
                geo.meta,
                (geo.likes + COALESCE(gl.c, 0))                                          AS likes,
                geo.started_at,
                geo.created_at,
                geo.verified_at,
                geo.deleted_at,
                geo.published_at,
                ST_Y(geo.location::geometry)                                            AS lat,
                ST_X(geo.location::geometry)                                            AS long,
                ST_Distance(geo.location, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography) AS dist_meters,
                influ.id   AS influencer_id,
                influ.name AS influencer_name,
                influ.avatar AS influencer_avatar,
                buss.id    AS business_id,
                buss.name  AS business_name,
                buss.avatar AS business_avatar,
                buss.meta  AS business_meta,
                cat.id     AS category_id,
                cat.name   AS category_name
            FROM geostories geo
            LEFT JOIN influencers influ ON geo.influencer_id = influ.id
            LEFT JOIN business     buss ON geo.business_id   = buss.id
            LEFT JOIN categories   cat  ON geo.category_id   = cat.id
            -- likes = base heredada del import + likes nuevos con usuario
            LEFT JOIN (
                SELECT geostory_id, COUNT(*)::int AS c FROM geostory_likes GROUP BY geostory_id
            ) gl ON gl.geostory_id = geo.id
            WHERE geo.deleted_at IS NULL
              AND (:ignore_id::uuid IS NULL OR geo.id <> :ignore_id::uuid)
              AND (:max_dist IS NULL OR
                   ST_Distance(geo.location, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography) <= :max_dist)
            ORDER BY geo.location <-> ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
            LIMIT :limit
        SQL;

        $conn = $this->em->getConnection();
        $rows = $conn->executeQuery($sql, [
            'lat'       => $latitude,
            'lng'       => $longitude,
            'ignore_id' => $ignoreId,
            'max_dist'  => $maxDistMeters,
            'limit'     => $limit,
        ])->fetchAllAssociative();

        return array_map(GeoStoryWithDistance::fromRow(...), $rows);
    }

    public function findByIdWithDistance(string $id, float $latitude, float $longitude): ?GeoStoryWithDistance
    {
        $sql = <<<'SQL'
            SELECT
                geo.id,
                geo.title,
                geo.description,
                geo.thumbnail,
                geo.url,
                geo.status,
                geo.meta,
                (geo.likes + COALESCE(gl.c, 0))                                          AS likes,
                geo.started_at,
                geo.created_at,
                geo.verified_at,
                geo.deleted_at,
                geo.published_at,
                ST_Y(geo.location::geometry)                                            AS lat,
                ST_X(geo.location::geometry)                                            AS long,
                ST_Distance(geo.location, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography) AS dist_meters,
                influ.id   AS influencer_id,
                influ.name AS influencer_name,
                influ.avatar AS influencer_avatar,
                buss.id    AS business_id,
                buss.name  AS business_name,
                buss.avatar AS business_avatar,
                buss.meta  AS business_meta,
                cat.id     AS category_id,
                cat.name   AS category_name
            FROM geostories geo
            LEFT JOIN influencers influ ON geo.influencer_id = influ.id
            LEFT JOIN business     buss ON geo.business_id   = buss.id
            LEFT JOIN categories   cat  ON geo.category_id   = cat.id
            -- likes = base heredada del import + likes nuevos con usuario
            LEFT JOIN (
                SELECT geostory_id, COUNT(*)::int AS c FROM geostory_likes GROUP BY geostory_id
            ) gl ON gl.geostory_id = geo.id
            WHERE geo.id = :id
        SQL;

        $conn = $this->em->getConnection();
        $row = $conn->executeQuery($sql, [
            'id'  => $id,
            'lat' => $latitude,
            'lng' => $longitude,
        ])->fetchAssociative();

        return $row !== false ? GeoStoryWithDistance::fromRow($row) : null;
    }

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
    ): array {
        $conditions = [
            'geo.deleted_at IS NULL',
            'geo.published_at IS NOT NULL',
        ];
        $params = [
            'lat'      => $latitude,
            'lng'      => $longitude,
            'limit'    => $size,
            'offset'   => $page * $size,
        ];

        if ($ignoreId !== null) {
            $conditions[] = 'geo.id <> :ignore_id::uuid';
            $params['ignore_id'] = $ignoreId;
        }

        if ($maxDistMeters !== null) {
            $conditions[] = 'ST_Distance(geo.location, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography) <= :max_dist';
            $params['max_dist'] = $maxDistMeters;
        }

        if ($businessId !== null) {
            $conditions[] = 'geo.business_id = :business_id::uuid';
            $params['business_id'] = $businessId;
        }

        if ($influencerId !== null) {
            $conditions[] = 'geo.influencer_id = :influencer_id::uuid';
            $params['influencer_id'] = $influencerId;
        }

        // Discovery feeds only show fully transcoded videos; an owner-scoped
        // query (a store/influencer profile) also surfaces its own in-progress
        // uploads so they appear immediately in "processing" state.
        $isOwnerScoped = $businessId !== null || $influencerId !== null;
        if (!$isOwnerScoped) {
            $conditions[] = "geo.status = 'ready'";
        }

        // Un vídeo sin revisar no es público: no sale en los feeds ni en el
        // perfil que visita otro. Sólo su dueño lo ve, y para eso el que
        // pregunta tiene que haberse identificado como tal.
        if (!$includeUnverified) {
            $conditions[] = 'geo.verified_at IS NOT NULL';
        }

        // Feed-type category filters use cat.slug via the categories JOIN.
        // category_id in geostories is a UUID — never compare it against slug strings directly.
        // Los feeds de descubrimiento ordenan por cercanía; el perfil de un
        // negocio o un influencer, por fecha —lo último que ha subido primero—,
        // que es como lo lee quien entra a ver a alguien.
        $orderBy = $isOwnerScoped
            ? 'geo.created_at DESC'
            : 'geo.location <-> ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography';

        if ($feedType === 'events' && $categoryId === null) {
            $conditions[] = "cat.slug = 'events'";
            $conditions[] = 'geo.started_at >= NOW()';
            $orderBy = 'geo.started_at ASC';
        } elseif ($feedType === 'geostories' && $categoryId === null) {
            $conditions[] = "cat.slug = 'news'";
            $conditions[] = "geo.created_at >= NOW() - INTERVAL '30 days'";
        } elseif ($feedType === 'tourism' && $categoryId === null) {
            $conditions[] = "cat.slug IN ('place', 'nature', 'culture')";
        } elseif ($feedType === 'local') {
            $conditions[] = "cat.slug NOT IN ('place', 'events', 'news', 'culture', 'nature')";
            if ($notCategoryId !== null) {
                $conditions[] = 'cat.slug != :not_cat';
                $params['not_cat'] = $notCategoryId;
            }
        }

        // Explicit category filter: accepts a UUID (from the category picker) or a slug.
        if ($categoryId !== null) {
            $conditions[] = '(cat.id::text = :category_id OR cat.slug = :category_id)';
            $params['category_id'] = $categoryId;
        }

        $where = implode(' AND ', $conditions);

        $sql = <<<SQL
            SELECT
                geo.id,
                geo.title,
                geo.description,
                geo.thumbnail,
                geo.url,
                geo.status,
                geo.provider_video_id,
                geo.meta,
                (geo.likes + COALESCE(gl.c, 0))                                          AS likes,
                geo.started_at,
                geo.created_at,
                geo.verified_at,
                geo.deleted_at,
                geo.published_at,
                ST_Y(geo.location::geometry)                                                        AS lat,
                ST_X(geo.location::geometry)                                                        AS long,
                ST_Distance(geo.location, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography)   AS dist_meters,
                influ.id     AS influencer_id,
                influ.name   AS influencer_name,
                influ.avatar AS influencer_avatar,
                buss.id      AS business_id,
                buss.name    AS business_name,
                buss.avatar  AS business_avatar,
                buss.meta    AS business_meta,
                cat.id       AS category_id,
                cat.name     AS category_name,
                COUNT(*) OVER() AS total_count
            FROM geostories geo
            LEFT JOIN influencers influ ON geo.influencer_id = influ.id
            LEFT JOIN business    buss  ON geo.business_id   = buss.id
            LEFT JOIN categories  cat   ON geo.category_id   = cat.id
            -- likes = base heredada del import + likes nuevos con usuario
            LEFT JOIN (
                SELECT geostory_id, COUNT(*)::int AS c FROM geostory_likes GROUP BY geostory_id
            ) gl ON gl.geostory_id = geo.id
            WHERE $where
            ORDER BY $orderBy
            LIMIT :limit OFFSET :offset
        SQL;

        $rows = $this->em->getConnection()
            ->executeQuery($sql, $params)
            ->fetchAllAssociative();

        $total = empty($rows) ? 0 : (int) $rows[0]['total_count'];

        return [
            'items' => array_map(GeoStoryWithDistance::fromRow(...), $rows),
            'total' => $total,
        ];
    }

    public function save(GeoStory $geoStory): void
    {
        $this->em->persist($geoStory);
        $this->em->flush();
    }

    public function delete(GeoStory $geoStory): void
    {
        $this->em->remove($geoStory);
        $this->em->flush();
    }
}
