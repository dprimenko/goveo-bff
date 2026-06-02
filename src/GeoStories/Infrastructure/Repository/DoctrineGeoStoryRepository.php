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
                geo.url,
                geo.meta,
                geo.likes,
                geo.started_at,
                geo.created_at,
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
                geo.url,
                geo.meta,
                geo.likes,
                geo.started_at,
                geo.created_at,
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
