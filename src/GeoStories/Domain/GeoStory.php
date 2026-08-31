<?php

declare(strict_types=1);

namespace App\GeoStories\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * The `location` field uses PostGIS GEOMETRY(Point, 4326).
 * It is stored and retrieved as GeoJSON array:
 *   ['type' => 'Point', 'coordinates' => [$longitude, $latitude]]
 */
#[ORM\Entity]
#[ORM\Table(name: 'geostories')]
class GeoStory
{
    // Transcoding lifecycle (Bunny Stream).
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY       = 'ready';
    public const STATUS_FAILED      = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description;

    #[ORM\Column(type: 'text')]
    private string $thumbnail;

    #[ORM\Column(type: 'text')]
    private string $url;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $likes;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $views;

    /**
     * PostGIS GEOMETRY(Point,4326) column.
     * Value format: ['type' => 'Point', 'coordinates' => [$lng, $lat]]
     */
    #[ORM\Column(
        type: 'geometry',
        nullable: true,
        options: ['geometry_type' => 'POINT', 'srid' => 4326],
    )]
    private mixed $location;

    #[ORM\Column(name: 'category_id', type: 'guid', nullable: true)]
    private ?string $categoryId;

    #[ORM\Column(name: 'influencer_id', type: 'guid', nullable: true)]
    private ?string $influencerId;

    #[ORM\Column(name: 'business_id', type: 'guid', nullable: true)]
    private ?string $businessId;

    #[ORM\Column(name: 'is_main', type: 'boolean', options: ['default' => false])]
    private bool $isMain;

    /** Transcoding status: processing | ready | failed. */
    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_READY])]
    private string $status;

    /** Bunny Stream video GUID (provider-neutral name). */
    #[ORM\Column(name: 'provider_video_id', type: 'string', length: 255, nullable: true)]
    private ?string $providerVideoId;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $meta;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt;

    #[ORM\Column(name: 'verified_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt;

    #[ORM\Column(name: 'published_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt;

    #[ORM\Column(name: 'started_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'ended_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt;

    public function __construct(
        string $id,
        string $thumbnail,
        string $url,
        ?string $title = null,
        ?string $description = null,
        ?string $categoryId = null,
        ?string $influencerId = null,
        ?string $businessId = null,
        mixed $location = null,
        bool $isMain = false,
        ?array $meta = null,
        string $status = self::STATUS_READY,
        ?string $providerVideoId = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->id = $id;
        $this->thumbnail = $thumbnail;
        $this->url = $url;
        $this->title = $title;
        $this->description = $description;
        $this->categoryId = $categoryId;
        $this->influencerId = $influencerId;
        $this->businessId = $businessId;
        $this->location = $location;
        $this->isMain = $isMain;
        $this->meta = $meta;
        $this->status = $status;
        $this->providerVideoId = $providerVideoId;
        $this->likes = 0;
        $this->views = 0;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
        $this->deletedAt = null;
        $this->verifiedAt = null;
        $this->publishedAt = null;
        $this->startedAt = null;
        $this->endedAt = null;
    }

    public function getId(): string { return $this->id; }
    public function getTitle(): ?string { return $this->title; }
    public function getDescription(): ?string { return $this->description; }
    public function getThumbnail(): string { return $this->thumbnail; }
    public function getUrl(): string { return $this->url; }
    public function getLikes(): int { return $this->likes; }
    public function getViews(): int { return $this->views; }
    public function getLocation(): mixed { return $this->location; }
    public function getCategoryId(): ?string { return $this->categoryId; }
    public function getInfluencerId(): ?string { return $this->influencerId; }
    public function getBusinessId(): ?string { return $this->businessId; }
    public function isMain(): bool { return $this->isMain; }
    public function getStatus(): string { return $this->status; }
    public function getProviderVideoId(): ?string { return $this->providerVideoId; }
    public function getMeta(): ?array { return $this->meta; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getEndedAt(): ?\DateTimeImmutable { return $this->endedAt; }

    /**
     * Set location from latitude/longitude. Stored as an EWKT string — the
     * PostGIS geometry type binds via ST_GeomFromEWKT(?), so a GeoJSON array
     * would fail on flush with "Array to string conversion".
     */
    public function setLocation(float $latitude, float $longitude): self
    {
        $this->location = sprintf('SRID=4326;POINT(%.7f %.7f)', $longitude, $latitude);
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function clearLocation(): self
    {
        $this->location = null;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setCategoryId(?string $categoryId): self
    {
        $this->categoryId = $categoryId;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function incrementViews(): self
    {
        ++$this->views;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function incrementLikes(): self
    {
        ++$this->likes;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setThumbnail(string $thumbnail): self
    {
        $this->thumbnail = $thumbnail;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setProviderVideoId(?string $providerVideoId): self
    {
        $this->providerVideoId = $providerVideoId;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markProcessing(): self
    {
        $this->status = self::STATUS_PROCESSING;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markReady(): self
    {
        $this->status = self::STATUS_READY;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markFailed(): self
    {
        $this->status = self::STATUS_FAILED;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isReady(): bool { return $this->status === self::STATUS_READY; }

    public function publish(): self
    {
        $this->publishedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function verify(): self
    {
        $this->verifiedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function softDelete(): self
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isDeleted(): bool { return $this->deletedAt !== null; }
    public function isPublished(): bool { return $this->publishedAt !== null; }
    public function isVerified(): bool { return $this->verifiedAt !== null; }
}
