<?php

declare(strict_types=1);

namespace App\GeoStories\Domain;

/**
 * Read model for geostory queries that include distance + joined data.
 * Replaces the Supabase SQL functions geostory_with_distance and nearby_geostories.
 */
final class GeoStoryWithDistance
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $title,
        public readonly ?string $url,
        public readonly mixed $meta,
        public readonly int $likes,
        public readonly float $lat,
        public readonly float $long,
        public readonly float $distMeters,
        public readonly ?\DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $deletedAt,
        public readonly ?\DateTimeImmutable $publishedAt,
        public readonly ?string $influencerId,
        public readonly ?string $influencerName,
        public readonly ?string $influencerAvatar,
        public readonly ?string $businessId,
        public readonly ?string $businessName,
        public readonly ?string $businessAvatar,
        public readonly mixed $businessMeta,
        public readonly ?string $categoryId,
        public readonly ?string $categoryName,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            title: $row['title'] ?? null,
            url: $row['url'] ?? null,
            meta: isset($row['meta']) ? json_decode($row['meta'], true) : null,
            likes: (int) ($row['likes'] ?? 0),
            lat: (float) $row['lat'],
            long: (float) $row['long'],
            distMeters: (float) $row['dist_meters'],
            startedAt: isset($row['started_at']) ? new \DateTimeImmutable($row['started_at']) : null,
            createdAt: isset($row['created_at']) ? new \DateTimeImmutable($row['created_at']) : null,
            deletedAt: isset($row['deleted_at']) ? new \DateTimeImmutable($row['deleted_at']) : null,
            publishedAt: isset($row['published_at']) ? new \DateTimeImmutable($row['published_at']) : null,
            influencerId: $row['influencer_id'] ?? null,
            influencerName: $row['influencer_name'] ?? null,
            influencerAvatar: $row['influencer_avatar'] ?? null,
            businessId: $row['business_id'] ?? null,
            businessName: $row['business_name'] ?? null,
            businessAvatar: $row['business_avatar'] ?? null,
            businessMeta: isset($row['business_meta']) ? json_decode($row['business_meta'], true) : null,
            categoryId: $row['category_id'] ?? null,
            categoryName: $row['category_name'] ?? null,
        );
    }
}
